<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ueber wen eine Mail hinausgeht (Entscheidung A15).
 *
 * **Genau einer je Mail.** Eine Mail an eine Patientin geht ueber das
 * Postfach der Praxis und nur darueber (B22); eine Mail an ein Konto --
 * Bestaetigung, Passwort, Code -- ueber den Server des Betreibers (B23). Wer
 * beides mischt, schickt eine Terminerinnerung aus unserer Infrastruktur
 * unter fremdem Namen oder einen Anmeldecode ueber das Postfach einer
 * Praxis.
 */
enum Versandweg: string
{
    case Praxis = 'praxis';
    case Plattform = 'plattform';

    public function label(): string
    {
        return match ($this) {
            self::Praxis => 'Postfach der Praxis',
            self::Plattform => 'Versand der Plattform',
        };
    }
}
