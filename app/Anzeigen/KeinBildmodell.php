<?php

declare(strict_types=1);

namespace App\Anzeigen;

/**
 * Kein Bildmodell angebunden -- dann entsteht kein Bild.
 *
 * **Ohne Schluessel keines**, und das ist die Vorgabe, nicht der
 * Ausnahmefall. Dieselbe Richtung wie `App\Agent\KeinSprachmodell`: was das
 * Produkt nicht erzeugen kann, erfindet es nicht.
 */
final class KeinBildmodell implements Bildmodell
{
    public function erzeuge(array $auftraege): Bildsatz
    {
        throw new BildNichtErzeugt('Es ist kein Bildmodell angebunden.');
    }

    public function angebunden(): bool
    {
        return false;
    }
}
