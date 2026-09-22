<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Metas drei Ebenen.
 *
 * Die beiden unteren fallen nach zwoelf Monaten weg (Entscheidung P9): die
 * Kampagnenebene traegt die Auswertung, die Anzeigenebene traegt die
 * Optimierung -- und die ist nach einem Jahr keine mehr.
 */
enum InsightLevel: string
{
    case Campaign = 'campaign';

    case AdSet = 'adset';

    case Ad = 'ad';

    public function label(): string
    {
        return match ($this) {
            self::Campaign => 'Kampagne',
            self::AdSet => 'Anzeigengruppe',
            self::Ad => 'Anzeige',
        };
    }

    /** Ueberlebt diese Ebene die Aufbewahrungsfrist aus P9? */
    public function bleibt(): bool
    {
        return $this === self::Campaign;
    }
}
