<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Die Markenfarbe einer Praxis als CSS-Variablen fuer die Buchungsseite.
 *
 * **Diese Klasse ist die Stelle, an der die Semantik gesperrt wird.**
 * docs/design/farben.md sagt: "Die Semantikvariablen sind von der
 * Ueberschreibung ausgenommen, das wird im Erzeuger der Branding-Stile
 * erzwungen, nicht durch Konvention." Hier steht der Erzeuger -- er gibt
 * genau drei Variablen aus und kann gar nichts anderes ausgeben.
 *
 * Der Grund ist kein Geschmack: waere die Markenfarbe einer Praxis gruen,
 * wuerde ein gruenes "bestanden" in der HWG-Ampel mehrdeutig. Bei einer
 * Funktion, deren Fehleinschaetzung bis zu 50.000 Euro kostet, ist das nicht
 * verhandelbar.
 */
final class Markenstil
{
    /**
     * Die einzigen Variablen, die eine Markenfarbe setzen darf.
     *
     * @var list<string>
     */
    public const ERLAUBT = ['--primary', '--primary-foreground', '--ring'];

    /** Weiss auf der Schaltflaeche muss lesbar bleiben (WCAG AA). */
    private const KONTRAST_MINDESTENS = 4.5;

    /**
     * @return array<string, string> leer, wenn keine Markenfarbe hinterlegt ist
     */
    public static function fuer(?string $hex): array
    {
        if ($hex === null || trim($hex) === '') {
            return [];
        }

        try {
            $farbe = Farbe::ausHex($hex);
        } catch (Throwable) {
            // Eine unbrauchbare Markenfarbe faellt auf die Produktfarbe
            // zurueck. Die Eingabe abzuweisen ist Sache von WP-07 -- eine
            // Buchungsseite, die wegen eines Tippfehlers im Farbwert gar
            // nicht mehr rendert, waere die schlechtere Antwort.
            return [];
        }

        [$helligkeit, $chroma, $farbton] = $farbe->oklch();

        $grundton = self::abgedunkeltBisLesbar($helligkeit, $chroma, $farbton);

        // Der Fokusrahmen liegt eine Stufe heller -- sonst verschwindet er
        // auf der Schaltflaeche, die er umranden soll.
        $rahmen = Farbe::ausOklch(
            min(1.0, Farbe::ausHslToken($grundton)->oklch()[0] + 0.07),
            $chroma,
            $farbton,
        );

        return [
            '--primary' => $grundton,
            '--primary-foreground' => '0 0% 100%',
            '--ring' => $rahmen->alsHslToken(),
        ];
    }

    /**
     * Dunkelt die Farbe ab, bis Weiss darauf lesbar ist -- und zwar auf dem
     * **gerundeten** Token.
     *
     * Farbton und Chroma bleiben unberuehrt, nur die Helligkeit wandert.
     * Neongelb wird hier zu einem dunklen Oliv, und das ist die richtige
     * Antwort: eine Schaltflaeche, die niemand lesen kann, ist schlimmer als
     * eine, die nicht ganz der Marke entspricht.
     *
     * **Geprueft wird der Token, nicht die gerechnete Farbe.** Der Token
     * rundet auf ganze Grad und ganze Prozent; Gold (#C9A227) erreichte
     * gerechnet 4,52 und gerundet 4,37. Wer die ungerundete Farbe prueft,
     * prueft etwas anderes als das, was im Browser steht.
     *
     * @return string HSL-Token
     */
    private static function abgedunkeltBisLesbar(float $helligkeit, float $chroma, float $farbton): string
    {
        $weiss = Farbe::weiss();
        $letzter = Farbe::ausOklch(0.0, $chroma, $farbton)->alsHslToken();

        for ($stufe = $helligkeit; $stufe > 0.0; $stufe -= 0.01) {
            $token = Farbe::ausOklch($stufe, $chroma, $farbton)->alsHslToken();

            if (Farbe::ausHslToken($token)->kontrastZu($weiss) >= self::KONTRAST_MINDESTENS) {
                return $token;
            }
        }

        return $letzter;
    }
}
