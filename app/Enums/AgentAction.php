<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was ein Durchlauf des Agenten getan hat.
 *
 * Steht im Protokoll (`agent_runs`) und ist das, was einer Praxis erklaert,
 * warum ihr Agent etwas getan oder gelassen hat.
 */
enum AgentAction: string
{
    /** Ein Vorschlag liegt im Eingabefeld. Gesendet hat niemand. */
    case Suggested = 'suggested';

    /** Selbst geantwortet. Erst ab WP-24 moeglich. */
    case Answered = 'answered';

    /** An einen Menschen uebergeben, mit Grund. */
    case Escalated = 'escalated';

    /** Der Agent war aus. */
    case Skipped = 'skipped';

    /** Das Modell hat nicht geantwortet. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Vorschlag',
            self::Answered => 'Automatisch beantwortet',
            self::Escalated => 'Übergeben',
            self::Skipped => 'Übersprungen',
            self::Failed => 'Fehlgeschlagen',
        };
    }
}
