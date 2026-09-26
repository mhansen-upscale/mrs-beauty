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
     * Erzeugt je Format ein Bild und liefert es **fertig heruntergeladen**
     * zurueck (WP-31b).
     *
     * **Gleichzeitig, nicht nacheinander**: ein Bild braucht ein bis drei
     * Minuten, drei nacheinander passten nicht in den Auftrag der
     * Warteschlange.
     *
     * **Ein gescheitertes Format wirft nicht**, es steht mit seinem Grund im
     * Bildsatz. Geworfen wird nur, wenn gar nichts geht -- etwa ohne
     * Anbindung.
     *
     * @param  array<string, string>  $auftraege  je Format (Wert von `Bildformat`) ein Auftrag
     *
     * @throws BildNichtErzeugt
     */
    public function erzeuge(array $auftraege): Bildsatz;

    /** Ist ueberhaupt eines angebunden? */
    public function angebunden(): bool;
}
