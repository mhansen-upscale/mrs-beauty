<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Enums\ComplianceCode;

/**
 * "Schmerzfrei", "risikolos", "ohne Ausfallzeit" (Paragraf 3 HWG).
 *
 * Aussagen ueber Risikofreiheit sind der haeufigste Verstoss in dieser
 * Branche -- und der, den niemand fuer einen haelt.
 */
final class RisikofreieAussage extends Wortregel
{
    protected function muster(): array
    {
        return [
            'schmerzfrei', 'schmerzlos', 'völlig schmerzfrei',
            'risikolos', 'risikofrei', 'ohne Risiko', 'ohne jedes Risiko',
            'ohne Ausfallzeit', 'keine Ausfallzeit', 'ohne Nebenwirkungen',
            'keine Nebenwirkungen', 'nebenwirkungsfrei', 'völlig unbedenklich',
        ];
    }

    protected function code(): ComplianceCode
    {
        return ComplianceCode::RiskFreeClaims;
    }

    protected function vorschlag(): string
    {
        return 'Sagen Sie, was Sie tun, damit es gut verträglich ist: „Wir betäuben die Stelle örtlich. '
            .'Die meisten empfinden die Behandlung als gut verträglich; Rötungen klingen meist in wenigen Stunden ab."';
    }
}
