<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wessen Kalender.
 *
 * Zwei Anbieter, ein Fall je Anbieter -- keine Tabelle. Das gemeinsame
 * Interface dahinter ist in WP-15 entstanden, **nachdem** beide gebaut waren:
 * docs/integrationen/kalender.md warnt ausdruecklich davor, es vorher zu
 * versuchen ("eine Abstraktion, die auf keinen von beiden richtig passt").
 */
enum CalendarProvider: string
{
    case Google = 'google';

    case Microsoft = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google Kalender',
            self::Microsoft => 'Microsoft Outlook',
        };
    }
}
