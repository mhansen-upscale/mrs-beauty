<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Entscheidung C4: Impersonation ist standardmaessig maskiert. Vollzugriff
 * gibt es nur mit Freigabe des Kunden, befristet und protokolliert.
 */
enum ImpersonationMode: string
{
    /** Die Oberflaeche ist sichtbar, personenbezogene Werte sind ersetzt. */
    case Masked = 'masked';

    /** Alles sichtbar. Nur nach Freigabe durch eine Inhaberin des Mandanten. */
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Masked => 'Maskiert',
            self::Full => 'Vollzugriff',
        };
    }
}
