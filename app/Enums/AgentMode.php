<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie weit der Agent in einer Konversation gehen darf.
 *
 * **`auto` bleibt bis WP-24 gesperrt** (`specs/README.md`). Der Fall steht
 * hier, weil die Spalte ihn braucht -- freigeschaltet wird er erst, wenn die
 * Guardrails aus WP-23 stehen. Ein Agent, der bucht, bevor er eskalieren
 * kann, ist der Fall, vor dem Regel 6 warnt.
 */
enum AgentMode: string
{
    /** Der Agent liest mit und tut nichts. */
    case Off = 'off';

    /** Er schlaegt eine Antwort vor, ein Mensch schickt sie ab. */
    case Suggest = 'suggest';

    /** Er antwortet selbst. Gesperrt bis WP-24. */
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Aus',
            self::Suggest => 'Vorschlagen',
            self::Auto => 'Automatisch',
        };
    }
}
