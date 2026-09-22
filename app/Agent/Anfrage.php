<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Was an das Sprachmodell geht -- **zweigeteilt, ab der ersten Zeile**.
 *
 * Regel 5 und Entscheidung G7: Anweisungen, Rolle und Katalog stammen aus dem
 * Produkt und stehen in `anweisung`. Was ein Mensch geschrieben hat, steht in
 * `daten` -- in einem abgegrenzten Block, der nie mit Anweisungen vermischt
 * wird.
 *
 * Die Trennung ist hier eine Eigenschaft der Datenstruktur und nicht eine
 * Frage der Sorgfalt am Aufrufort: wer Inhalte in die Anweisung schreiben
 * will, muss dafuer diese Klasse aendern.
 */
final class Anfrage
{
    public function __construct(
        /** Kommt ausschliesslich aus dem Produkt. */
        public readonly string $anweisung,

        /** Kommt von aussen. Daten, keine Anweisungen. */
        public readonly string $daten,

        public readonly string $modell,
        public readonly int $hoechstenTokens = 1024,
    ) {}

    /**
     * Der Datenblock, wie er im Prompt steht.
     *
     * Die Markierung ist kein Schmuck: sie ist die Stelle, an der sich
     * nachlesen laesst, wo fremder Text anfaengt und aufhoert. Ein
     * Datenblock, der dieselbe Markierung enthaelt, wird entschaerft --
     * sonst liesse sich die Grenze von innen verschieben.
     */
    public function datenblock(): string
    {
        $inhalt = str_replace(['<nachricht>', '</nachricht>'], ['&lt;nachricht&gt;', '&lt;/nachricht&gt;'], $this->daten);

        return "<nachricht>\n".$inhalt."\n</nachricht>";
    }
}
