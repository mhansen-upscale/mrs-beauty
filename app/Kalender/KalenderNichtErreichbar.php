<?php

declare(strict_types=1);

namespace App\Kalender;

use RuntimeException;

/**
 * Der Anbieter hat nicht oder mit einem Fehler geantwortet.
 *
 * Abzugrenzen von ZugangEntzogen: **das** heisst "die Verbindung ist tot und
 * muss erneuert werden", **dies** heisst "gerade nicht, spaeter noch einmal".
 * Der Job laeuft in die Wiederholung, die Verbindung bleibt aktiv.
 *
 * Die Meldung traegt nie den Antworttext des Anbieters -- der enthaelt bei
 * Kalender-APIs regelmaessig die Adresse des Kontos.
 */
final class KalenderNichtErreichbar extends RuntimeException
{
    public static function mitStatus(int $status): self
    {
        return new self("Der Kalenderanbieter hat mit Status {$status} geantwortet.");
    }
}
