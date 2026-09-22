<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Support\Fehlereinordnung;
use RuntimeException;

/**
 * Der Anbieter hat den Versand abgelehnt.
 *
 * Traegt die Einordnung mit sich: ob wiederholt wird und was mit der
 * Verbindung geschieht, entscheidet Fehlereinordnung nach der Tabelle aus
 * docs/integrationen/meta.md -- nicht die Aufrufstelle.
 */
final class Kanalfehler extends RuntimeException
{
    public function __construct(public readonly Fehlereinordnung $einordnung)
    {
        parent::__construct('Der Kanal hat den Versand abgelehnt: '.$einordnung->kurzgrund);
    }
}
