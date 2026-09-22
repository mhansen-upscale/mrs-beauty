<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Das Ergebnis einer Pruefung.
 *
 * **Gelb ist kein schwaches Gruen.** Es heisst: jemand muss hinsehen. Bei
 * einer Funktion, deren Fehleinschaetzung bis zu 50.000 Euro kostet, ist das
 * die richtige Vorgabe -- und dieselbe Haltung wie Regel 6.
 */
enum Ampel: string
{
    case Gruen = 'green';

    case Gelb = 'yellow';

    case Rot = 'red';

    public function label(): string
    {
        return match ($this) {
            self::Gruen => 'Keine Beanstandung',
            self::Gelb => 'Bitte prüfen',
            self::Rot => 'So nicht veröffentlichen',
        };
    }

    /** Darf das hinaus? */
    public function gibtFrei(): bool
    {
        return $this === self::Gruen;
    }

    /** Die strengere von beiden. */
    public function schaerferAls(self $andere): bool
    {
        $rang = [self::Gruen->value => 0, self::Gelb->value => 1, self::Rot->value => 2];

        return $rang[$this->value] > $rang[$andere->value];
    }
}
