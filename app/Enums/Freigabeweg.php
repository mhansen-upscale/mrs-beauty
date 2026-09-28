<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie eine Inhaberin den Vollzugriff freigegeben hat (WP-34b, C15).
 *
 * Zwei Wege zu derselben Freigabe: Die Sitzung danach ist dieselbe,
 * befristet, protokolliert und in jeder Antwort erkennbar.
 */
enum Freigabeweg: string
{
    /** In der eigenen Sitzung auf „Vollzugriff freigeben" geklickt (C4). */
    case Klick = 'klick';

    /** Eine Einmal-PIN erzeugt und dem Support genannt (C15). */
    case Pin = 'pin';

    public function label(): string
    {
        return match ($this) {
            self::Klick => 'per Klick',
            self::Pin => 'per Einmal-PIN',
        };
    }
}
