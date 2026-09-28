<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Durch welche Tuer jemand hereinkommt (WP-34a, WP-35).
 *
 * Die Praxen melden sich unter `/login` an, der Betreiber unter
 * `/backoffice/anmelden` (C14). Der Code-Schritt ist fuer beide derselbe --
 * aber eine ausstehende Anmeldung gilt nur an dem Eingang, an dem sie
 * begonnen hat, und fuehrt am Ende dorthin zurueck.
 */
enum Anmeldeeingang: string
{
    case Praxis = 'praxis';
    case Betreiber = 'betreiber';

    /** Die Anmeldeseite dieses Eingangs. */
    public function anmeldung(): string
    {
        return match ($this) {
            self::Praxis => 'login',
            self::Betreiber => 'backoffice.anmelden',
        };
    }

    /** Die Routen des Code-Schritts: ohne Aktion die Seite selbst. */
    public function code(?string $aktion = null): string
    {
        $basis = match ($this) {
            self::Praxis => 'login.zwei-faktor',
            self::Betreiber => 'backoffice.anmelden.code',
        };

        return $aktion === null ? $basis : "{$basis}.{$aktion}";
    }

    /**
     * "Angemeldet bleiben" gibt es nur fuer die Praxen. Ein Betreiberkonto
     * reicht quer ueber alle Praxen und bleibt nie dauerhaft angemeldet (C14).
     */
    public function erlaubtMerken(): bool
    {
        return $this === self::Praxis;
    }
}
