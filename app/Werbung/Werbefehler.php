<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Support\Fehlereinordnung;
use RuntimeException;

/**
 * Ein Fehlschlag gegenueber Metas Marketing-API.
 *
 * Traegt die Einordnung mit sich: ob wiederholt wird und was mit dem
 * Werbekonto geschieht. Ein ungueltiges Token wird beim zwanzigsten Versuch
 * nicht gueltiger.
 */
final class Werbefehler extends RuntimeException
{
    public function __construct(public readonly Fehlereinordnung $einordnung)
    {
        parent::__construct($einordnung->kurzgrund);
    }
}
