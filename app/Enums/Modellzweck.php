<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wofuer ein Sprachmodell ausserhalb eines Assistenzlaufs gefragt wurde
 * (WP-34d). Der Agent fuehrt seine Kosten in `agent_runs`; alles andere
 * stand bisher nirgends.
 */
enum Modellzweck: string
{
    /** Die woechentlichen Textentwuerfe der Anzeigen (WP-31). */
    case Anzeigentexte = 'anzeigentexte';
}
