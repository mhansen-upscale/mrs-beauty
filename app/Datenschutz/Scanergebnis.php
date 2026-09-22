<?php

declare(strict_types=1);

namespace App\Datenschutz;

/**
 * Was eine Virenpruefung ueber eine Datei sagt.
 *
 * **Drei Zustaende, nicht zwei.** "Nicht geprueft" ist kein "sauber" -- und
 * genau diese Verwechslung war die Luecke, die WP-33 geschlossen hat: die
 * Vorgabe aus WP-18 schrieb `clean`, obwohl niemand hingesehen hatte.
 */
enum Scanergebnis: string
{
    case Clean = 'clean';

    case Infected = 'infected';

    /** Kein Pruefer angebunden oder nicht erreichbar. Nicht ausliefern. */
    case Unscanned = 'unscanned';

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'Geprüft',
            self::Infected => 'Befund',
            self::Unscanned => 'Ungeprüft',
        };
    }

    /** Darf die Datei ausgeliefert werden? */
    public function gibtFrei(): bool
    {
        return $this === self::Clean;
    }
}
