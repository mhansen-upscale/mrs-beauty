<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie eine Person ihre Anmeldung zusaetzlich absichert (WP-35, C16).
 *
 * Genau eines je Person, gespeichert in `users.zwei_faktor_verfahren` als
 * VARCHAR (Entscheidung A11). Es zaehlt erst mit `zwei_faktor_bestaetigt_at`.
 */
enum ZweiFaktorVerfahren: string
{
    /** TOTP nach RFC 6238, mit Wiederherstellungscodes. Empfohlen. */
    case Authenticator = 'authenticator';

    /**
     * Ein Code an die Kontoadresse. Schuetzt vor einem geleakten Passwort,
     * nicht vor einem uebernommenen Postfach -- ueber dasselbe Postfach
     * laeuft das Zuruecksetzen des Passworts.
     */
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Authenticator => 'Authenticator-App',
            self::Email => 'Code per E-Mail',
        };
    }
}
