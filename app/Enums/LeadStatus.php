<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wo eine Anfrage steht.
 *
 * Die Liste ist nicht frei erweiterbar: `docs/fachlogik/attribution.md`
 * definiert "Abschluesse" als "Leads mit Status `won`", und abweichende
 * Auslegung in Berichten ist der schnellste Weg, Vertrauen in die Zahlen zu
 * verlieren.
 */
enum LeadStatus: string
{
    /** Eingegangen, noch keine Reaktion der Praxis. */
    case New = 'new';

    /** Die Praxis hat reagiert, es laeuft. */
    case Contacted = 'contacted';

    /** Ein Termin steht. */
    case Scheduled = 'scheduled';

    /**
     * Gewonnen -- und das heisst **erschienen**, nicht gebucht.
     *
     * Ein gebuchter Termin, den niemand wahrnimmt, darf nicht als Erfolg
     * zaehlen; sonst misst der ROAS Absichten statt Umsatz.
     */
    case Won = 'won';

    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Neu',
            self::Contacted => 'In Klärung',
            self::Scheduled => 'Termin',
            self::Won => 'Gewonnen',
            self::Lost => 'Verloren',
        };
    }

    /**
     * Laeuft der Vorgang noch?
     *
     * Entscheidung D4 haengt daran: ein offener Lead verhindert einen zweiten,
     * ein geschlossener nicht.
     */
    public function istOffen(): bool
    {
        return $this !== self::Won && $this !== self::Lost;
    }

    /** Hat die Praxis schon reagiert? */
    public function istReaktion(): bool
    {
        return $this !== self::New;
    }
}
