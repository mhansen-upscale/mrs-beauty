<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wo eine Demo-Anfrage von der Startseite steht (WP-38).
 *
 * **Drei Zustaende, keine Pipeline.** Wer daraus eine Praxis macht, legt sie
 * an und laedt ein; das Backoffice fuehrt keinen Vertrieb, es haelt nur fest,
 * dass sich jemand gekuemmert hat.
 */
enum DemoRequestStatus: string
{
    /** Eingegangen, noch niemand hat sich gemeldet. */
    case New = 'new';

    /** Der Vertrieb hat sich gemeldet, es laeuft. */
    case Contacted = 'contacted';

    /** Abgeschlossen -- gleich wie. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Neu',
            self::Contacted => 'Kontaktiert',
            self::Closed => 'Erledigt',
        };
    }
}
