<?php

declare(strict_types=1);

namespace App\Kalender;

use App\Enums\CalendarProvider;
use App\Kalender\Google\GoogleDienst;
use App\Kalender\Microsoft\MicrosoftDienst;
use App\Models\CalendarConnection;

/**
 * Der Weg vom Anbieter zur Umsetzung.
 *
 * Bewusst eine abgeschlossene Zuordnung und keine Registrierung zur Laufzeit:
 * die Anbieter stehen als Enum fest (Entscheidung A11), und ein dritter waere
 * ein Arbeitspaket, kein Konfigurationseintrag.
 */
final class Kalenderdienste
{
    public function fuer(CalendarProvider $anbieter): Kalenderdienst
    {
        return match ($anbieter) {
            CalendarProvider::Google => app(GoogleDienst::class),
            CalendarProvider::Microsoft => app(MicrosoftDienst::class),
        };
    }

    public function zu(CalendarConnection $verbindung): Kalenderdienst
    {
        return $this->fuer($verbindung->provider);
    }
}
