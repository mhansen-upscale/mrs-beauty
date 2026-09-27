<?php

declare(strict_types=1);

namespace App\Betrieb;

use App\Enums\AuditEvent;
use App\Enums\ConnectionStatus;
use App\Enums\SubscriptionChangeStatus;
use App\Models\AdAccount;
use App\Models\AuditLog;
use App\Models\CalendarConnection;
use App\Models\ChannelConnection;
use App\Models\ChannelRawEvent;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\SubscriptionChange;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Was gerade nicht laeuft.
 *
 * **Regel 4**: „Stille Ausfaelle sind der Normalfall und keine Ausnahme: jede
 * Verbindung wird ueberwacht, ein Ausfall erzeugt einen Hinweis **im
 * Produkt**, nicht nur im Log."
 *
 * Bis WP-33 galt das je Verbindung -- der Kalender zeigte seinen Zustand, die
 * Kanaele ihren. Was fehlte, war die Ebene darunter: ein fehlgeschlagener
 * Auftrag landete in `failed_jobs` und wurde dort von niemandem gelesen, ein
 * Rohereignis ohne Leser blieb liegen, und ein Scheduler, der nicht laeuft,
 * fiel erst auf, wenn eine Praxis fragte, warum keine Erinnerung ankam.
 */
final class Betriebslage
{
    public function __construct(
        private readonly TenantContext $mandant,
        private readonly Warteschlangen $warteschlangen,
    ) {}

    /**
     * Die Lage dieses Mandanten.
     *
     * @return array<string, mixed>
     */
    public function fuerMandant(?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();

        $kanaele = ChannelConnection::query()
            ->whereIn('status', [
                ConnectionStatus::Expired->value,
                ConnectionStatus::Degraded->value,
                ConnectionStatus::Suspended->value,
            ])
            ->get();

        $kalender = CalendarConnection::query()
            ->where('status', '!=', 'active')
            ->get();

        return [
            'gestoerteKanaele' => $kanaele
                ->map(fn (ChannelConnection $verbindung): array => [
                    'kanal' => $verbindung->channel->label(),
                    'status' => $verbindung->status->label(),
                    'grund' => $verbindung->last_error,
                    'seit' => $verbindung->failed_at?->toIso8601String(),
                ])
                ->values()
                ->all(),

            'gestoerteKalender' => $kalender->count(),

            // Ein Werbekonto mit abgelaufenem Zugang faellt sonst erst auf,
            // wenn jemand fragt, warum seit Wochen keine Anfragen kommen
            // (Regel 4). Getrennte Konten zaehlen nicht: das war eine
            // Entscheidung, keine Stoerung.
            'gestoerteWerbekonten' => AdAccount::query()
                ->whereNull('disconnected_at')
                ->whereIn('status', [
                    ConnectionStatus::Expired->value,
                    ConnectionStatus::Degraded->value,
                    ConnectionStatus::Suspended->value,
                ])
                ->count(),

            // Ein Rohereignis ohne Verarbeitung laesst sich 14 Tage lang
            // erneut einspielen -- danach ist die Nachricht weg (WP-19).
            'liegengebliebeneEreignisse' => ChannelRawEvent::query()
                ->offen()
                ->where('created_at', '<', $jetzt->subHour())
                ->count(),
        ];
    }

    /**
     * Die Lage der Installation -- ueber alle Mandanten.
     *
     * Diese Zahlen gehoeren nicht einer Praxis, sondern dem Betrieb: eine
     * Warteschlange ist keine Mandantensache.
     *
     * @return array<string, mixed>
     */
    public function fuerInstallation(?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();

        return [
            'fehlgeschlageneAuftraege' => $this->fehlgeschlagene(),
            'juengsterFehlschlag' => $this->juengsterFehlschlag(),

            // **Der Ersatz fuer den Supervisor-Test.** Seit das Produkt auf
            // der verwalteten Warteschlange von Laravel Cloud laeuft,
            // stehen die Arbeiter in der Oberflaeche des Anbieters -- kein
            // Test kann noch pruefen, ob es sie gibt. Bleibt die
            // Beobachtung: liegt etwas, und holt niemand ab?
            'stehendeWarteschlangen' => $this->warteschlangen->stehende($jetzt),
            'offeneEreignisse' => $this->mandant->acrossTenants(
                'Betriebsuebersicht zaehlt liegengebliebene Rohereignisse aller Mandanten',
                fn (): int => ChannelRawEvent::query()->offen()->count(),
            ),
            'mandanten' => $this->mandant->acrossTenants(
                'Betriebsuebersicht zaehlt die Mandanten',
                fn (): int => Organization::query()->whereNull('suspended_at')->count(),
            ),

            // **Ein gescheiterter Abo-Eingriff ist eine Stoerung** (WP-34c,
            // Regel 4): der Betreiber glaubt sonst, eine Praxis sei pausiert
            // oder gekuendigt, und Stripe bucht weiter ab.
            'gescheiterteAboEingriffe' => $this->mandant->acrossTenants(
                'Betriebsuebersicht zaehlt gescheiterte Abo-Eingriffe aller Mandanten',
                fn (): int => SubscriptionChange::query()
                    ->where('status', SubscriptionChangeStatus::Failed->value)
                    ->where('created_at', '>=', $jetzt->subDays((int) config('mrs.backoffice.eingriffe_rueckblick_tage')))
                    ->count(),
            ),

            // **Eine Paketfassung, die bei Stripe scheiterte** (WP-06b): die
            // vorige gilt weiter, aber der Betreiber glaubt, der neue Preis
            // stehe. Nur solange keine juengere gilt.
            'gescheitertePaketfassungen' => PlanVersion::query()
                ->where('stripe_state', PlanVersion::GESCHEITERT)
                ->where('number', '>', (int) PlanVersion::query()->whereNotNull('activated_at')->max('number'))
                ->count(),

            // Ein Abo, das nicht umgestellt werden konnte, und ein Preis bei
            // Stripe, den keine Fassung kennt -- beides wartet auf jemanden.
            'paketHinweise' => $this->mandant->acrossTenants(
                'Betriebsuebersicht zaehlt Paket-Hinweise aller Mandanten',
                fn (): int => AuditLog::query()
                    ->whereIn('event', [AuditEvent::SubscriptionPlanChangeFailed->value, AuditEvent::SubscriptionPriceUnknown->value])
                    ->where('occurred_at', '>=', $jetzt->subDays((int) config('mrs.backoffice.eingriffe_rueckblick_tage')))
                    ->count(),
            ),
        ];
    }

    /** Ist etwas so, dass jemand hinsehen sollte? */
    public function auffaellig(?CarbonImmutable $jetzt = null): bool
    {
        $installation = $this->fuerInstallation($jetzt);

        return $installation['fehlgeschlageneAuftraege'] > 0
            || $installation['offeneEreignisse'] > 0
            || $installation['gescheiterteAboEingriffe'] > 0
            || $installation['gescheitertePaketfassungen'] > 0
            || $installation['paketHinweise'] > 0
            || $installation['stehendeWarteschlangen'] !== [];
    }

    private function fehlgeschlagene(): int
    {
        return (int) DB::table('failed_jobs')->count();
    }

    private function juengsterFehlschlag(): ?string
    {
        $wert = DB::table('failed_jobs')->max('failed_at');

        return is_string($wert) ? $wert : null;
    }
}
