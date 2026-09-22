<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wonach die Auswertung gruppiert.
 *
 * **Nur die Kampagne traegt Kosten.** Meta rechnet je Kampagne ab, nicht je
 * Behandler -- bei jeder anderen Aufschluesselung bleiben Cost per Lead, CAC
 * und ROAS leer, und zwar sichtbar.
 */
enum Aufschluesselung: string
{
    case Kampagne = 'kampagne';

    case Behandlung = 'behandlung';

    case Behandler = 'behandler';

    case Standort = 'standort';

    public function label(): string
    {
        return match ($this) {
            self::Kampagne => 'Kampagne',
            self::Behandlung => 'Behandlung',
            self::Behandler => 'Behandler',
            self::Standort => 'Standort',
        };
    }

    public function traegtKosten(): bool
    {
        return $this === self::Kampagne;
    }
}
