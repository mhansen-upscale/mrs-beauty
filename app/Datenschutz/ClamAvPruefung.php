<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Models\Attachment;
use Carbon\CarbonImmutable;

/**
 * Die Virenpruefung ueber clamd.
 *
 * **Nicht erreichbar heisst ungeprueft, nicht sauber.** Ein Pruefdienst, der
 * gerade neu startet, darf keine Datei freigeben -- und der Vermerk sagt,
 * woran es lag, ohne die Meldung des Dienstes zu uebernehmen (die traegt den
 * Dateinamen mit sich).
 *
 * Der Fund selbst wird festgehalten: welche Signatur angeschlagen hat, ist
 * fuer die Praxis die einzige Information, mit der sie etwas anfangen kann.
 */
final class ClamAvPruefung implements Virenpruefung
{
    public function __construct(private readonly Scanverbindung $verbindung) {}

    public function pruefe(Attachment $anhang, string $inhalt): void
    {
        $anhang->scanned_at = CarbonImmutable::now();

        try {
            $antwort = $this->verbindung->pruefe($inhalt);
        } catch (ScannerNichtErreichbar) {
            $anhang->scan_result = Scanergebnis::Unscanned->value;
            $anhang->save();

            return;
        }

        $anhang->scan_result = $this->deute($antwort);
        $anhang->save();
    }

    /**
     * clamd antwortet `stream: OK` oder `stream: <Signatur> FOUND`.
     *
     * Alles andere ist ein Fehler des Dienstes -- und damit ungeprueft.
     */
    private function deute(string $antwort): string
    {
        if (str_ends_with($antwort, 'OK')) {
            return Scanergebnis::Clean->value;
        }

        if (! str_contains($antwort, 'FOUND')) {
            return Scanergebnis::Unscanned->value;
        }

        // Nur die Signatur, nicht die ganze Zeile: die enthaelt den
        // Stream-Namen und spaeter vielleicht mehr.
        $signatur = trim(str_replace(['stream:', 'FOUND'], '', $antwort));

        return mb_substr(Scanergebnis::Infected->value.':'.$signatur, 0, 32);
    }
}
