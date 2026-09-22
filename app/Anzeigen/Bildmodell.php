<?php

declare(strict_types=1);

namespace App\Anzeigen;

/**
 * Was ein Bildmodell koennen muss.
 *
 * **Eine Schnittstelle, damit der Rest des Produkts sie nicht kennt** --
 * dieselbe Haltung wie bei `App\Agent\Sprachmodell`. Der Wechsel des
 * Anbieters ist eine Konfigurationszeile, kein Umbau.
 */
interface Bildmodell
{
    /**
     * Erzeugt ein Bild und liefert es **fertig heruntergeladen** zurueck.
     *
     * Wirft BildNichtErzeugt, wenn der Anbieter nicht antwortet oder ablehnt.
     */
    public function erzeuge(string $auftrag): Bild;

    /** Ist ueberhaupt eines angebunden? */
    public function angebunden(): bool;
}
