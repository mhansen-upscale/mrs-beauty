<?php

declare(strict_types=1);

namespace App\Agent;

use RuntimeException;

/**
 * Das Sprachmodell hat nicht geantwortet.
 *
 * Traegt einen **Kurzgrund**, keine Meldung des Anbieters: die enthaelt
 * regelmaessig den gesendeten Text und damit den Inhalt einer Nachricht.
 */
final class ModellNichtErreichbar extends RuntimeException
{
    public function __construct(public readonly string $kurzgrund)
    {
        parent::__construct('Das Sprachmodell hat nicht geantwortet: '.$kurzgrund);
    }
}
