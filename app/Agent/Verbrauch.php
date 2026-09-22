<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Was ein Durchlauf an Modellaufrufen gekostet hat.
 *
 * **Gezaehlt, nicht geschaetzt.** Die Token kommen aus der Antwort des
 * Anbieters; nur der Preis je Million steht in der Konfiguration -- und die
 * traegt ihre Fundstelle als Kommentar.
 *
 * Gerechnet wird in **Zehntel-Cent**: ein Aufruf kostet regelmaessig weniger
 * als einen Cent, und eine Rechnung, die auf null rundet, sagt nichts. Die
 * Umrechnung in eine Waehrung und die Abrechnung im Abo sind WP-06.
 */
final class Verbrauch
{
    public int $eingabe = 0;

    public int $ausgabe = 0;

    public function zaehle(Antwort $antwort): Antwort
    {
        $this->eingabe += $antwort->eingabeTokens;
        $this->ausgabe += $antwort->ausgabeTokens;

        return $antwort;
    }

    public function kostenZehntelCent(string $modell): int
    {
        $preise = (array) config('mrs.agent.model_pricing', []);
        $preis = $preise[$modell] ?? null;

        if (! is_array($preis)) {
            // Ein unbekanntes Modell bekommt keine erfundene Rechnung.
            return 0;
        }

        $eingabe = $this->eingabe * (int) ($preis['input'] ?? 0);
        $ausgabe = $this->ausgabe * (int) ($preis['output'] ?? 0);

        return (int) round(($eingabe + $ausgabe) / 1_000_000);
    }
}
