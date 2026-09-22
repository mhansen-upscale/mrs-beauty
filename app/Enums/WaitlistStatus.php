<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wo ein Wartelisteneintrag steht (docs/fachlogik/warteliste.md).
 */
enum WaitlistStatus: string
{
    /** Wartet und kommt fuer Angebote in Frage. */
    case Active = 'active';

    /** Ein Angebot ist offen -- K9 laesst kein zweites zu. */
    case Offered = 'offered';

    case Booked = 'booked';

    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Wartet',
            self::Offered => 'Angebot offen',
            self::Booked => 'Termin gefunden',
            self::Expired => 'Abgelaufen',
        };
    }
}
