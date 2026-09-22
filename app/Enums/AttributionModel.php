<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie eine Buchung ihrer Quelle zugeordnet wird.
 *
 * **Nicht im Schema festgeschrieben** (docs/fachlogik/attribution.md): alle
 * Touches werden gespeichert, das Modell wird zur Abfragezeit gerechnet. Eine
 * Zuordnung, die beim Schreiben faellt, laesst sich spaeter nicht anders
 * ansehen -- und genau das will eine Praxis wissen, die fragt, ob ihre
 * Anzeige den ersten oder den letzten Anstoss gab.
 */
enum AttributionModel: string
{
    case FirstTouch = 'first_touch';

    case LastTouch = 'last_touch';

    /** Vorgabe: ein Direktaufruf ist keine Quelle, sondern das Fehlen einer. */
    case LastNonDirect = 'last_non_direct';

    case Linear = 'linear';

    public function label(): string
    {
        return match ($this) {
            self::FirstTouch => 'Erster Kontakt',
            self::LastTouch => 'Letzter Kontakt',
            self::LastNonDirect => 'Letzter mit erkennbarer Quelle',
            self::Linear => 'Gleichmäßig verteilt',
        };
    }

    public function beschreibung(): string
    {
        return match ($this) {
            self::FirstTouch => 'Der Anstoß zählt — wer die Person überhaupt aufmerksam gemacht hat.',
            self::LastTouch => 'Der letzte Schritt zählt, auch ein Direktaufruf.',
            self::LastNonDirect => 'Der letzte Schritt mit erkennbarer Quelle. Direktaufrufe werden übersprungen.',
            self::Linear => 'Jeder Kontakt bekommt denselben Anteil.',
        };
    }
}
