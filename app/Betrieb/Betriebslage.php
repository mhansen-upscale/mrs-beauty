<?php

declare(strict_types=1);

namespace App\Betrieb;

use App\Enums\ConnectionStatus;
use App\Models\AdAccount;
use App\Models\CalendarConnection;
use App\Models\ChannelConnection;
use App\Models\ChannelRawEvent;
use App\Models\Organization;
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
    public function __construct(private readonly TenantContext $mandant) {}

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
            'offeneEreignisse' => $this->mandant->acrossTenants(
                'Betriebsuebersicht zaehlt liegengebliebene Rohereignisse aller Mandanten',
                fn (): int => ChannelRawEvent::query()->offen()->count(),
            ),
            'mandanten' => $this->mandant->acrossTenants(
                'Betriebsuebersicht zaehlt die Mandanten',
                fn (): int => Organization::query()->whereNull('suspended_at')->count(),
            ),
        ];
    }

    /** Ist etwas so, dass jemand hinsehen sollte? */
    public function auffaellig(?CarbonImmutable $jetzt = null): bool
    {
        $installation = $this->fuerInstallation($jetzt);

        return $installation['fehlgeschlageneAuftraege'] > 0
            || $installation['offeneEreignisse'] > 0;
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
