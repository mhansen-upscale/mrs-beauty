<?php

declare(strict_types=1);

namespace App\Backoffice;

use App\Abrechnung\Nutzungsuebersicht;
use App\Abrechnung\Paket;
use App\Betrieb\Betriebslage;
use App\Enums\BookingChannel;
use App\Enums\CalendarConnectionStatus;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionStatus;
use App\Models\AdAccount;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Wie es um die Installation steht -- die Uebersicht des Betreibers.
 *
 * **Summen ueber alle Praxen, keine Zahl je Person** und kein Inhalt: wie
 * alles im Backoffice wird gezaehlt, nicht gelesen (WP-34).
 *
 * **Ein Querzugriff, nicht einer je Praxis.** Alles laeuft in genau einem
 * `acrossTenants()` mit Begruendung; innen wird nicht mehr protokolliert.
 * Und keine Abfrage haengt an der Zahl der Praxen.
 *
 * **Der Umsatz ist hochgerechnet**, nicht gebucht: je zahlender Praxis der
 * Grundpreis **ihrer** Paketfassung (WP-06b) -- sonst rechnete die Uebersicht
 * den Bestandsschutz weg. Die Rechnungen liegen bei Stripe.
 */
final class Installationskennzahlen
{
    /** @var list<ConnectionStatus> */
    private const GESTOERT = [ConnectionStatus::Expired, ConnectionStatus::Degraded, ConnectionStatus::Suspended];

    /** @var list<BookingChannel> */
    private const SELBST_GEBUCHT = [BookingChannel::Public, BookingChannel::Agent, BookingChannel::Waitlist];

    public function __construct(
        private readonly TenantContext $mandant,
        private readonly Nutzungsuebersicht $nutzung,
        private readonly Betriebslage $lage,
        private readonly Paket $paket,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jetzt(?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();

        /** @var array<string, mixed> */
        return $this->mandant->acrossTenants(
            'Backoffice zeigt dem Betreiber die Kennzahlen seiner Installation (WP-34)',
            fn (): array => [
                ...$this->praxenUndAbos($jetzt),
                'monat' => $this->monat($jetzt),
                'betrieb' => [
                    ...$this->lage->fuerInstallation($jetzt),
                    'praxenMitStoerung' => $this->praxenMitStoerung(),
                    'praxenOhnePostfach' => $this->praxenOhnePostfach(),
                ],
            ],
        );
    }

    /**
     * Praxen und ihre Abos.
     *
     * Zwei schmale Abfragen, gerechnet wird hier: die Testphase endet an
     * `trial_ends_at` -- und **ohne Abo-Zeile ist eine Praxis in der
     * Testphase** (WP-06), gerechnet ab ihrem Anlegen.
     *
     * @return array{praxen: array<string, int>, abos: array<string, int>}
     */
    private function praxenUndAbos(CarbonImmutable $jetzt): array
    {
        $praxen = Organization::query()->get(['id', 'created_at', 'suspended_at']);

        $abos = Subscription::query()
            // Was Subscription::zugang() liest -- und nicht mehr.
            ->get(['organization_id', 'plan_version_id', 'status', 'stripe_subscription_id', 'trial_ends_at', 'paused_at', 'discount_ends_at'])
            ->keyBy(fn (Subscription $abo): string => (string) $abo->getAttribute('organization_id'));

        // Wenige Zeilen -- einmal geladen statt je Abo.
        $fassungen = PlanVersion::query()->get()->keyBy(fn (PlanVersion $fassung): string => (string) $fassung->getKey());
        $aktuell = $this->paket->aktuell();
        $testphaseTage = $aktuell->trial_days;
        $mrrCent = 0;
        $warnungBis = $jetzt->addDays((int) config('mrs.backoffice.testphase_warnung_tage'));
        $neuSeit = $jetzt->subDays((int) config('mrs.backoffice.neue_praxen_tage'));

        $zahlen = [
            'zahlend' => 0, 'zahlungOffen' => 0, 'unbezahlt' => 0, 'gekuendigt' => 0, 'pausiert' => 0,
            'testphase' => 0, 'testphaseEndetBald' => 0, 'testphaseAbgelaufen' => 0,
        ];
        $gesperrt = 0;
        $neu = 0;

        foreach ($praxen as $praxis) {
            /** @var CarbonImmutable $angelegt */
            $angelegt = $praxis->getAttribute('created_at');

            $gesperrt += $praxis->suspended_at !== null ? 1 : 0;
            $neu += $angelegt->greaterThanOrEqualTo($neuSeit) ? 1 : 0;

            /** @var Subscription|null $abo */
            $abo = $abos->get((string) $praxis->getKey());

            // **Dieselbe Stelle wie ueberall** (WP-34c): was das Abo erlaubt,
            // weiss Subscription::zugang() -- und ohne Zeile zaehlt die
            // Testphase ab dem Anlegen.
            $ende = $abo instanceof Subscription ? $abo->testphasenende() : $angelegt->addDays($testphaseTage);
            $lage = $abo instanceof Subscription
                ? $abo->zugang($jetzt)
                : ($ende->greaterThan($jetzt) ? SubscriptionAccess::Trial : SubscriptionAccess::TrialExpired);

            $zahlungOffen = $abo instanceof Subscription && $abo->status === SubscriptionStatus::PastDue;

            $zaehltAls = match ($lage) {
                SubscriptionAccess::Open => $zahlungOffen ? ['zahlend', 'zahlungOffen'] : ['zahlend'],
                SubscriptionAccess::Trial => $ende->lessThanOrEqualTo($warnungBis) ? ['testphase', 'testphaseEndetBald'] : ['testphase'],
                SubscriptionAccess::TrialExpired => ['testphaseAbgelaufen'],
                SubscriptionAccess::Paused => ['pausiert'],
                SubscriptionAccess::Unpaid => ['unbezahlt'],
                SubscriptionAccess::Canceled => ['gekuendigt'],
            };

            foreach ($zaehltAls as $schluessel) {
                $zahlen[$schluessel]++;
            }

            // Offen ist nur ein Abo mit Zeile -- ohne Zeile ist es die Testphase.
            // **Dieselbe Regel wie die Finanzuebersicht** (WP-34d): Ein
            // Gratismonat bringt keinen Umsatz.
            if ($lage === SubscriptionAccess::Open && $abo->rechnetGrundpreisAb($jetzt)) {
                $mrrCent += ($fassungen->get((string) ($abo->getAttributes()['plan_version_id'] ?? '')) ?? $aktuell)->base_cents;
            }
        }

        return [
            'praxen' => [
                'gesamt' => $praxen->count(),
                'gesperrt' => $gesperrt,
                'neu' => $neu,
                'neuTage' => (int) config('mrs.backoffice.neue_praxen_tage'),
            ],
            'abos' => [
                ...$zahlen,

                // **Hochgerechnet**: offene Zahlungen zaehlen mit, denn
                // Stripe mahnt noch (WP-06) -- verloren ist die Praxis erst
                // bei `unpaid`. Eine Pause bringt nichts (B17).
                'mrrCent' => $mrrCent,
            ],
        ];
    }

    /**
     * Die Nutzung des laufenden Monats, ueber alle Praxen.
     *
     * **Dieselben Regeln wie im Kontingent** -- Nutzungsuebersicht zaehlt im
     * Querzugriff die ganze Installation. Die Modellkosten sind US-Cent
     * (`mrs.agent.model_pricing` rechnet in Zehntel-US-Cent).
     *
     * @return array<string, int>
     */
    private function monat(CarbonImmutable $jetzt): array
    {
        $nutzung = $this->nutzung->fuerMonat($jetzt);

        $termine = fn () => Appointment::query()
            ->aktiv()
            ->whereBetween('created_at', [$jetzt->startOfMonth(), $jetzt->endOfMonth()]);

        return [
            'kostenpflichtig' => (int) $nutzung['kostenpflichtigeNachrichten'],
            'agentenlaeufe' => (int) $nutzung['agentenlaeufe'],
            'modellkostenUsdCent' => (int) round(((int) $nutzung['modellkosten_zehntel_cent']) / 10),
            'termine' => $termine()->count(),
            'selbstGebucht' => $termine()
                ->whereIn('booked_via', array_map(fn (BookingChannel $kanal): string => $kanal->value, self::SELBST_GEBUCHT))
                ->count(),
        ];
    }

    /**
     * Wie viele Praxen keine Mail an eine Patientin verschicken koennen (B22).
     *
     * **Die Zahl fuer den Rollout von WP-36**: ohne eigenes Postfach gehen
     * weder Terminmails noch Antworten hinaus. Gesperrte Praxen zaehlen nicht
     * -- sie verschicken ohnehin nichts.
     */
    private function praxenOhnePostfach(): int
    {
        $mitPostfach = ChannelConnection::query()
            ->where('channel', ChannelType::Email->value)
            ->whereNotNull('smtp_host')
            ->whereNotNull('smtp_port')
            ->whereNotNull('sender_id')
            ->whereIn('status', [ConnectionStatus::Active->value, ConnectionStatus::Degraded->value])
            ->pluck('organization_id')
            ->map(strval(...))
            ->unique();

        return Organization::query()
            ->whereNull('suspended_at')
            ->pluck('id')
            ->map(strval(...))
            ->diff($mitPostfach)
            ->count();
    }

    /**
     * Wie viele Praxen eine gestoerte Verbindung haben -- jede einmal, gleich
     * wie viele Verbindungen es sind.
     */
    private function praxenMitStoerung(): int
    {
        $status = array_map(fn (ConnectionStatus $zustand): string => $zustand->value, self::GESTOERT);

        return collect()
            ->merge(ChannelConnection::query()->whereIn('status', $status)->pluck('organization_id'))
            ->merge(CalendarConnection::query()->where('status', '!=', CalendarConnectionStatus::Active->value)->pluck('organization_id'))
            ->merge(AdAccount::query()->whereNull('disconnected_at')->whereIn('status', $status)->pluck('organization_id'))
            ->map(fn (mixed $kennung): string => (string) $kennung)
            ->unique()
            ->count();
    }
}
