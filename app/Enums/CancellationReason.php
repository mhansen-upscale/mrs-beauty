<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Warum ein Termin abgesagt wurde.
 *
 * Eine abgeschlossene Aufzaehlung und **kein Freitext**. Ein Freitextfeld an
 * dieser Stelle fuellt sich mit Begruendungen wie "Patientin hat Angst vor
 * dem Eingriff" -- also mit Gesundheitsdaten in einer Spalte, die niemand als
 * solche behandelt. Entscheidung P1 und Regel 3.
 */
enum CancellationReason: string
{
    /** Der Kontakt hat abgesagt. */
    case Contact = 'contact';

    /** Die Praxis hat abgesagt. */
    case Practice = 'practice';

    /** Doppelt eingetragen. */
    case Duplicate = 'duplicate';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contact => 'Kontakt hat abgesagt',
            self::Practice => 'Praxis hat abgesagt',
            self::Duplicate => 'Doppelt eingetragen',
            self::Other => 'Sonstiges',
        };
    }
}
