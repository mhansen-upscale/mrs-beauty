<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Warum eine Anfrage nicht zustande kam.
 *
 * Eine abgeschlossene Aufzaehlung und **kein Freitext**: das Feld steht neben
 * dem Behandlungswunsch, und ein Textfeld an dieser Stelle fuellt sich mit
 * genau den Angaben, die Entscheidung D2 heraushalten soll.
 *
 * Die Liste ist grob mit Absicht. Wer feiner auswerten will, braucht kein
 * weiteres Feld, sondern ein Gespraech.
 */
enum LeadLostReason: string
{
    case NoResponse = 'no_response';

    case TooExpensive = 'too_expensive';

    case WentElsewhere = 'went_elsewhere';

    case NoFit = 'no_fit';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NoResponse => 'Keine Rückmeldung',
            self::TooExpensive => 'Preis',
            self::WentElsewhere => 'Woanders behandelt',
            self::NoFit => 'Nicht geeignet',
            self::Other => 'Sonstiges',
        };
    }
}
