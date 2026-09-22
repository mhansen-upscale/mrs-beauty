<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was ein ausgehendes Event zeigt.
 *
 * Entscheidung B4 setzt `busy_only` als Standard und meint damit einen
 * zweiten Modus. Regel 3 ist davon **nicht** beruehrt: ausgehende Eintraege
 * tragen keinen Kontaktnamen und keine Behandlung, in keinem Modus.
 *
 * `details` fuegt deshalb keinen Inhalt hinzu, sondern einen Weg zum Inhalt --
 * die Beschreibung traegt einen Link in die Terminansicht. Wer wissen will,
 * wer kommt, meldet sich an. Der Kalender bleibt neutral, auch wenn das
 * Handy auf dem Tresen liegt.
 */
enum CalendarPrivacyMode: string
{
    case BusyOnly = 'busy_only';

    case Details = 'details';

    public function label(): string
    {
        return match ($this) {
            self::BusyOnly => 'Nur belegt',
            self::Details => 'Mit Link in die Terminansicht',
        };
    }

    public function mitLink(): bool
    {
        return $this === self::Details;
    }
}
