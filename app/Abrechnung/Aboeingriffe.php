<?php

declare(strict_types=1);

namespace App\Abrechnung;

use App\Abrechnung\Stripe\Stripeclient;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionChangeAction;
use App\Enums\SubscriptionChangeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\AboEingriffAusfuehren;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Eingriffe des Betreibers in das Abo einer Praxis (WP-34c, B17).
 *
 * **Der Betreiber beauftragt, Stripe entscheidet, der Webhook berichtet.**
 * Hier entsteht der Auftrag -- mit Begruendung, Namen und Protokolleintrag
 * bei der Praxis --, und AboEingriffAusfuehren spricht mit Stripe, nie im
 * Anfragezyklus (Regel 4). Den Zustand des Abos schreibt dieser Dienst
 * nicht.
 *
 * **Ausser im Testbetrieb.** Ohne Stripe-Schluessel gibt es niemanden, der
 * berichten koennte; dann wirkt der Eingriff sofort lokal und traegt
 * `ohne_stripe` -- sichtbar im Mandantenblatt, damit niemand glaubt, bei
 * Stripe sei etwas geschehen.
 *
 * **Die Testphase lebt nur bei uns** und wirkt immer sofort.
 */
final class Aboeingriffe
{
    public function __construct(
        private readonly TenantContext $mandant,
        private readonly Kontingente $kontingente,
        private readonly Stripeclient $stripe,
        private readonly AuditLogger $protokoll,
    ) {}

    /**
     * @param  array<string, mixed>  $parameter  Ohne Personenbezug -- etwa `bis` fuer die Pause
     *
     * @throws ValidationException Wenn der Eingriff zum Abo nicht passt
     */
    public function beauftrage(
        Organization $praxis,
        SubscriptionChangeAction $aktion,
        string $grund,
        User $betreiber,
        array $parameter = [],
    ): SubscriptionChange {
        return $this->mandant->runAs($praxis, function () use ($praxis, $aktion, $grund, $betreiber, $parameter): SubscriptionChange {
            $abo = $this->kontingente->abo();
            $mitStripe = $this->stripe->angebunden();

            $this->pruefe($abo, $aktion, $mitStripe);

            $eingriff = $this->lege($aktion, $grund, $betreiber, $parameter + ($mitStripe ? [] : ['ohne_stripe' => true]));

            $this->protokoll->record(
                ereignis: AuditEvent::SubscriptionChangeRequested,
                gegenstand: $abo,
                kontext: ['aktion' => $aktion->value, ...$parameter, 'ohne_stripe' => ! $mitStripe],
                begruendung: $grund,
            );

            if (! $mitStripe) {
                $this->wendeLokalAn($abo, $eingriff);

                return $eingriff;
            }

            AboEingriffAusfuehren::dispatch((string) $praxis->uuid, (string) $eingriff->uuid);

            return $eingriff->refresh();
        });
    }

    /**
     * Verlaengert die Testphase -- sofort, ohne Stripe (B18).
     *
     * **Von heute an gerechnet, nicht vom abgelaufenen Ende.** Wer nach drei
     * Wochen Ablauf um 14 Tage verlaengert, schenkte sonst nichts.
     *
     * @throws ValidationException Wenn schon ein Stripe-Abo laeuft
     */
    public function verlaengereTestphase(Organization $praxis, int $tage, string $grund, User $betreiber): SubscriptionChange
    {
        return $this->mandant->runAs($praxis, function () use ($tage, $grund, $betreiber): SubscriptionChange {
            $abo = $this->kontingente->abo();

            if ($abo->status !== SubscriptionStatus::Trialing || $this->hatStripeAbo($abo)) {
                throw ValidationException::withMessages([
                    'tage' => 'Die Testphase lässt sich nur verlängern, solange kein Abo läuft.',
                ]);
            }

            $jetzt = CarbonImmutable::now();
            $ende = $abo->testphasenende();

            $neuesEnde = ($ende->greaterThan($jetzt) ? $ende : $jetzt)->addDays($tage);

            $abo->trial_ends_at = $neuesEnde;
            $abo->save();

            $eingriff = $this->lege(SubscriptionChangeAction::ExtendTrial, $grund, $betreiber, ['tage' => $tage]);
            $this->erledige($eingriff);

            $this->protokoll->record(
                ereignis: AuditEvent::SubscriptionTrialExtended,
                gegenstand: $abo,
                kontext: ['tage' => $tage, 'bis' => $neuesEnde->toDateString()],
                begruendung: $grund,
            );

            return $eingriff;
        });
    }

    /**
     * Passt der Eingriff zum Abo, wie es gerade ist?
     *
     * **Am Feld abgewiesen, nicht in der Warteschlange** -- ein Auftrag, der
     * erst bei Stripe scheitert, steht als Stoerung in der Betriebslage.
     */
    private function pruefe(Subscription $abo, SubscriptionChangeAction $aktion, bool $mitStripe): void
    {
        $fehler = match (true) {
            $mitStripe && ! $this->hatStripeAbo($abo) => 'Diese Praxis hat noch kein Abo bei Stripe.',
            $aktion === SubscriptionChangeAction::Pause && $abo->zugang() === SubscriptionAccess::Paused => 'Das Abo ist bereits pausiert.',
            $aktion === SubscriptionChangeAction::Resume && $abo->zugang() !== SubscriptionAccess::Paused => 'Das Abo ist nicht pausiert.',
            $aktion === SubscriptionChangeAction::CancelPeriodEnd && ($abo->cancel_at_period_end || $abo->status === SubscriptionStatus::Canceled) => 'Das Abo ist bereits gekündigt.',
            $aktion === SubscriptionChangeAction::RevokeCancel && ! $abo->cancel_at_period_end => 'Es gibt keine Kündigung zum Periodenende, die sich zurücknehmen ließe.',
            $aktion === SubscriptionChangeAction::CancelNow && $abo->status === SubscriptionStatus::Canceled => 'Das Abo ist bereits beendet.',
            $aktion === SubscriptionChangeAction::FreeMonth && $mitStripe && ! is_string(config('services.stripe.free_month_coupon')) => 'Für den Gratismonat ist kein Gutschein hinterlegt (STRIPE_FREE_MONTH_COUPON_ID).',
            default => null,
        };

        if ($fehler !== null) {
            throw ValidationException::withMessages(['aktion' => $fehler]);
        }
    }

    /**
     * Der Testbetrieb: was Stripe sonst melden wuerde, sofort und lokal.
     */
    private function wendeLokalAn(Subscription $abo, SubscriptionChange $eingriff): void
    {
        $jetzt = CarbonImmutable::now();
        $bis = $eingriff->parameters['bis'] ?? null;

        match ($eingriff->action) {
            SubscriptionChangeAction::Pause => $abo->forceFill([
                'paused_at' => $jetzt,
                'pause_resumes_at' => is_string($bis) ? CarbonImmutable::parse($bis) : null,
            ]),
            SubscriptionChangeAction::Resume => $abo->forceFill(['paused_at' => null, 'pause_resumes_at' => null]),
            SubscriptionChangeAction::CancelPeriodEnd => $abo->forceFill([
                'cancel_at_period_end' => true,
                'cancel_at' => $abo->period_ends_at ?? $jetzt->addMonth(),
            ]),
            SubscriptionChangeAction::RevokeCancel => $abo->forceFill(['cancel_at_period_end' => false, 'cancel_at' => null]),
            SubscriptionChangeAction::CancelNow => $abo->forceFill([
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => $jetzt,
                'cancel_at_period_end' => false,
            ]),
            SubscriptionChangeAction::FreeMonth => $abo->forceFill(['discount_ends_at' => $abo->period_ends_at ?? $jetzt->addMonth()]),
            SubscriptionChangeAction::ExtendTrial => null,
        };

        $abo->save();
        $this->erledige($eingriff);
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function lege(SubscriptionChangeAction $aktion, string $grund, User $betreiber, array $parameter): SubscriptionChange
    {
        $eingriff = new SubscriptionChange;
        $eingriff->action = $aktion;
        $eingriff->parameters = $parameter === [] ? null : $parameter;
        $eingriff->reason = $grund;
        $eingriff->status = SubscriptionChangeStatus::Pending;
        $eingriff->requested_by_user_id = $betreiber->getKey();
        $eingriff->idempotency_key = (string) Str::uuid();
        $eingriff->save();

        return $eingriff;
    }

    private function erledige(SubscriptionChange $eingriff): void
    {
        $eingriff->status = SubscriptionChangeStatus::Done;
        $eingriff->completed_at = CarbonImmutable::now();
        $eingriff->save();
    }

    private function hatStripeAbo(Subscription $abo): bool
    {
        return is_string($abo->stripe_subscription_id) && $abo->stripe_subscription_id !== '';
    }
}
