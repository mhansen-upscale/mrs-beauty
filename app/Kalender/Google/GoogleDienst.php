<?php

declare(strict_types=1);

namespace App\Kalender\Google;

use App\Enums\CalendarProvider;
use App\Kalender\Abonnement;
use App\Kalender\Ereignisseite;
use App\Kalender\Kalenderangaben;
use App\Kalender\Kalenderdienst;
use App\Kalender\Zugangsdaten;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;

/**
 * Google als Kalenderanbieter.
 *
 * Duenn mit Absicht: die Arbeit steckt in GoogleZugang (Token) und
 * GoogleKalender (HTTP). Diese Klasse setzt nur die beiden an die
 * gemeinsame Schnittstelle -- und baut die Nutzlast, weil genau die nicht
 * gemeinsam ist.
 */
final class GoogleDienst implements Kalenderdienst
{
    public function __construct(
        private readonly GoogleZugang $zugang,
        private readonly GoogleKalender $kalender,
    ) {}

    public function anbieter(): CalendarProvider
    {
        return CalendarProvider::Google;
    }

    public function weiterleitung(string $state): string
    {
        return $this->zugang->weiterleitung($state);
    }

    public function tausche(string $code): Zugangsdaten
    {
        return $this->zugang->tausche($code);
    }

    public function widerrufe(CalendarConnection $verbindung): void
    {
        $this->zugang->widerrufe($verbindung);
    }

    public function kalender(CalendarConnection $verbindung): Kalenderangaben
    {
        return $this->kalender->kalender($verbindung);
    }

    public function ereignisse(
        CalendarConnection $verbindung,
        ?string $zeiger,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): Ereignisseite {
        return $this->kalender->ereignisse($verbindung, $zeiger, $von, $bis);
    }

    public function lege(CalendarConnection $verbindung, Appointment $termin): string
    {
        return $this->kalender->lege($verbindung, Ausgangsereignis::fuer($termin, $verbindung));
    }

    public function aktualisiere(CalendarConnection $verbindung, string $kennung, Appointment $termin): bool
    {
        return $this->kalender->aktualisiere($verbindung, $kennung, Ausgangsereignis::fuer($termin, $verbindung));
    }

    public function entferne(CalendarConnection $verbindung, string $kennung): void
    {
        $this->kalender->entferne($verbindung, $kennung);
    }

    public function beobachte(CalendarConnection $verbindung, string $geheimnis, string $adresse): Abonnement
    {
        return $this->kalender->beobachte($verbindung, $geheimnis, $adresse);
    }

    public function erneuere(CalendarConnection $verbindung, string $geheimnis, string $adresse): Abonnement
    {
        return $this->kalender->erneuere($verbindung, $geheimnis, $adresse);
    }

    public function beende(CalendarConnection $verbindung): void
    {
        $this->kalender->beende($verbindung);
    }
}
