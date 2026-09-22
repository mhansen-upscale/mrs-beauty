<?php

declare(strict_types=1);

namespace App\Compliance;

/**
 * Eine Regel des Regelwerks.
 *
 * Eine Klasse je Regel, damit jede ihre Fundstelle, ihren Grund und ihren
 * Formulierungsvorschlag bei sich traegt -- und damit eine neue Regel nichts
 * an den bestehenden aendert.
 */
interface Regel
{
    /**
     * @return list<Befund>
     */
    public function pruefe(Pruefgegenstand $gegenstand): array;
}
