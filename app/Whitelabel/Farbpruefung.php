<?php

declare(strict_types=1);

namespace App\Whitelabel;

use App\Support\Farbe;
use Throwable;

/**
 * Das Tor vor der Markenfarbe.
 *
 * **Der Erzeuger darf nachsichtig sein, das Formular nicht.**
 * `App\Support\Markenstil` steht im Ausliefern: er dunkelt ab und faellt bei
 * Unsinn auf die Produktfarbe zurueck, damit eine Buchungsseite nicht wegen
 * eines Tippfehlers gar nicht mehr rendert. Genau deshalb braucht es hier
 * jemanden, der Nein sagt -- sonst traegt jemand Neongelb ein, sieht ein
 * dunkles Oliv und haelt das Produkt fuer kaputt.
 *
 * Die drei Regeln stehen in `docs/design/farben.md`, Abschnitt "Validierung
 * bei der Eingabe", und verhalten sich verschieden: Hinweis, Warnung,
 * Ablehnung.
 */
final class Farbpruefung
{
    public function pruefe(?string $eingabe): Farbbefund
    {
        $eingabe = $eingabe === null ? null : trim($eingabe);

        if ($eingabe === null || $eingabe === '') {
            // Keine Farbe ist eine gueltige Antwort: dann gilt die
            // Produktfarbe.
            return Farbbefund::angenommen('');
        }

        try {
            $farbe = Farbe::ausHex($eingabe);
        } catch (Throwable) {
            return Farbbefund::abgelehnt('Das ist kein Farbwert. Erwartet wird eine Angabe wie #1F5D5B.');
        }

        // **Regel 3: Extremwerte werden abgelehnt, nicht korrigiert.**
        $kontrast = $farbe->kontrastZu(Farbe::weiss());

        if ($kontrast < (float) config('mrs.whitelabel.min_kontrast_eingabe')) {
            return Farbbefund::abgelehnt(
                'Diese Farbe ist zu hell für Schaltflächen. Um weiße Schrift darauf lesbar zu machen, '
                .'müssten wir sie so stark abdunkeln, dass nichts von ihr übrig bliebe. Bitte einen '
                .'kräftigeren Ton wählen.'
            );
        }

        $hinweise = [];

        // **Regel 1: zu hell -> Hinweis, nicht Ablehnung.**
        if ($kontrast < (float) config('mrs.whitelabel.min_kontrast_ausgabe')) {
            $hinweise[] = sprintf(
                'Weiße Schrift erreicht auf %s nur %s:1. Auf der Buchungsseite verwenden wir deshalb '
                .'einen abgedunkelten Ton derselben Farbe — Farbton und Sättigung bleiben.',
                mb_strtoupper($eingabe),
                number_format($kontrast, 2, ',', '.'),
            );
        }

        // **Regel 2: Farbton nahe einer Semantikfarbe -> Warnung.**
        $semantik = $this->semantiknaehe($farbe);

        if ($semantik !== null) {
            $hinweise[] = sprintf(
                'Ihre Markenfarbe liegt nahe an %s. Sie wird verwendet — die Statusfarben bleiben '
                .'davon unberührt, damit eine Fehlermeldung als Fehlermeldung erkennbar bleibt.',
                $semantik,
            );
        }

        return Farbbefund::angenommen(mb_strtoupper($eingabe), $hinweise);
    }

    /**
     * Welcher Semantikfarbe die Eingabe nahe kommt -- oder keiner.
     *
     * Der Grund steht in `App\Support\Markenstil`: waere die Markenfarbe
     * gruen, wuerde ein gruenes "bestanden" in der HWG-Ampel mehrdeutig. Bei
     * einer Funktion, deren Fehleinschaetzung bis zu 50.000 Euro kostet, ist
     * das nicht verhandelbar -- gesperrt ist die Semantik ohnehin, gewarnt
     * wird trotzdem.
     */
    private function semantiknaehe(Farbe $farbe): ?string
    {
        $token = $farbe->alsHslToken();
        $farbton = (float) explode(' ', $token)[0];
        $toleranz = (float) config('mrs.whitelabel.semantik_toleranz');

        /** @var array<string, int> $semantik */
        $semantik = (array) config('mrs.whitelabel.semantik_farbtoene');

        foreach ($semantik as $name => $grad) {
            // Ueber 0 Grad hinweg: 355 ist naeher an Rot als an Magenta.
            $abstand = abs($farbton - $grad);
            $abstand = min($abstand, 360 - $abstand);

            if ($abstand <= $toleranz) {
                return $name;
            }
        }

        return null;
    }
}
