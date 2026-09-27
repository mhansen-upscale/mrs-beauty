<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Abrechnung\Stripe\Stripeclient;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\SubscriptionChangeAction;
use App\Enums\SubscriptionChangeStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Support\Fehlereinordnung;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Traegt einen Abo-Eingriff zu Stripe (WP-34c, B17).
 *
 * **In der Warteschlange, nicht im Anfragezyklus** (Regel 4), mit dem
 * Idempotenzschluessel des Eingriffs: eine Wiederholung nach verlorener
 * Antwort pausiert nichts zweimal und schenkt keinen zweiten Monat.
 *
 * **Erledigt heisst: Stripe hat angenommen.** Was fuer das Abo daraus folgt,
 * meldet der Webhook. Ausfaelle und Rate Limits werden wiederholt; eine
 * fachliche Ablehnung ist endgueltig und steht am Eingriff, im
 * Mandantenblatt und in der Betriebslage -- nicht nur im Log.
 */
final class AboEingriffAusfuehren implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly string $organisation,
        public readonly string $eingriff,
    ) {
        $this->onQueue('default');

        // Der Eingriff entsteht in der Anfrage; ein schnellerer Arbeiter
        // faende die Zeile sonst noch nicht.
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->eingriff;
    }

    public function handle(TenantContext $mandant, Stripeclient $stripe, AuditLogger $protokoll): void
    {
        $praxis = Organization::query()->whereUuid($this->organisation)->first();

        if (! $praxis instanceof Organization) {
            return;
        }

        $mandant->runAs($praxis, function () use ($stripe, $protokoll): void {
            $eingriff = SubscriptionChange::query()->whereUuid($this->eingriff)->first();

            if (! $eingriff instanceof SubscriptionChange || $eingriff->status !== SubscriptionChangeStatus::Pending) {
                return;
            }

            $abo = Subscription::query()->first();

            if (! $abo instanceof Subscription || ! is_string($abo->stripe_subscription_id) || $abo->stripe_subscription_id === '') {
                $this->scheitere($eingriff, 'Diese Praxis hat kein Abo bei Stripe.', $protokoll);

                return;
            }

            try {
                $antwort = $this->rufe($stripe, $eingriff, $abo->stripe_subscription_id);
            } catch (ConnectionException $fehler) {
                // Wiederholen -- die Warteschlange versucht es erneut.
                throw new RuntimeException('Stripe ist nicht erreichbar; der Eingriff wird wiederholt.', previous: $fehler);
            }

            if ($antwort->successful()) {
                $eingriff->status = SubscriptionChangeStatus::Done;
                $eingriff->completed_at = CarbonImmutable::now();
                $eingriff->save();

                return;
            }

            /** @var array<string, mixed> $koerper */
            $koerper = (array) $antwort->json();
            $einordnung = Fehlereinordnung::ausStripeAntwort($antwort->status(), $koerper);

            if ($einordnung->wiederholen) {
                throw new RuntimeException("Stripe antwortet mit {$antwort->status()}; der Eingriff wird wiederholt.");
            }

            $this->scheitere($eingriff, $einordnung->grund(), $protokoll);
        });
    }

    /**
     * Alle Versuche verbraucht -- dann ist es endgueltig, und es steht dort,
     * wo es jemand sieht.
     */
    public function failed(?Throwable $fehler): void
    {
        $praxis = Organization::query()->whereUuid($this->organisation)->first();

        if (! $praxis instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($praxis, function (): void {
            $eingriff = SubscriptionChange::query()->whereUuid($this->eingriff)->first();

            if ($eingriff instanceof SubscriptionChange && $eingriff->status === SubscriptionChangeStatus::Pending) {
                $this->scheitere($eingriff, 'Stripe war auch nach mehreren Versuchen nicht erreichbar.', app(AuditLogger::class));
            }
        });
    }

    private function rufe(Stripeclient $stripe, SubscriptionChange $eingriff, string $abo): Response
    {
        $schluessel = $eingriff->idempotency_key;
        $bis = $eingriff->parameters['bis'] ?? null;

        return match ($eingriff->action) {
            SubscriptionChangeAction::Pause => $stripe->pausiere($abo, is_string($bis) ? CarbonImmutable::parse($bis)->startOfDay() : null, $schluessel),
            SubscriptionChangeAction::Resume => $stripe->setzeFort($abo, $schluessel),
            SubscriptionChangeAction::CancelPeriodEnd => $stripe->kuendigeZumPeriodenende($abo, $schluessel),
            SubscriptionChangeAction::RevokeCancel => $stripe->nimmKuendigungZurueck($abo, $schluessel),
            SubscriptionChangeAction::CancelNow => $stripe->kuendigeSofort($abo, $schluessel),
            SubscriptionChangeAction::FreeMonth => $stripe->gewaehreGutschein($abo, (string) config('services.stripe.free_month_coupon'), $schluessel),
            SubscriptionChangeAction::ExtendTrial => throw new RuntimeException('Die Testphase lebt nicht bei Stripe.'),
        };
    }

    private function scheitere(SubscriptionChange $eingriff, string $grund, AuditLogger $protokoll): void
    {
        $eingriff->status = SubscriptionChangeStatus::Failed;
        $eingriff->error = mb_substr($grund, 0, 500);
        $eingriff->completed_at = CarbonImmutable::now();
        $eingriff->save();

        // Beim Mandanten -- die Praxis soll nachlesen koennen, was mit ihrem
        // Abo versucht wurde. Die Meldung von Stripe steht am Eingriff.
        $protokoll->record(
            ereignis: AuditEvent::SubscriptionChangeFailed,
            gegenstand: $eingriff,
            kontext: ['aktion' => $eingriff->action->value],
            begruendung: $eingriff->reason,
        );
    }
}
