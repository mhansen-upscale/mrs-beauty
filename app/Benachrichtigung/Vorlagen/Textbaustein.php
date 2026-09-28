<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Vorlagen;

/**
 * Setzt die Platzhalter einer Vorlage (Entscheidung C17).
 *
 * **Ein Wert wird nie zu Markup.** Heisst eine Patientin `**x**
 * [a](https://evil.test)`, steht genau das in der Mail -- kein Fettdruck,
 * kein Link. Deshalb wird jeder Wert fuer Markdown maskiert, bevor er in den
 * Text kommt. HTML maskiert danach Blade beim Ausgeben der Zeile.
 *
 * Der Betreff ist Klartext: dort maskiert niemand, dort rendert auch niemand.
 */
final class Textbaustein
{
    /**
     * @param  array<string, string>  $werte  Platzhalter => Wert, roh
     */
    public static function setze(Mailtext $text, array $werte): Mailinhalt
    {
        return new Mailinhalt(
            betreff: self::klartext(self::ersetze($text->betreff, $werte, maskiert: false)),
            anrede: self::ersetze($text->anrede, $werte),
            einleitung: self::absaetze(self::ersetze($text->einleitung, $werte)),
            schluss: self::absaetze(self::ersetze($text->schluss, $werte)),
            gruss: self::ersetze($text->gruss, $werte),
        );
    }

    /**
     * Maskiert, was Markdown als Auszeichnung laese.
     *
     * `<`, `>` und `&` bleiben: die setzt Blade beim Ausgeben in Entitaeten,
     * und ein Backslash davor machte aus `&lt;` den sichtbaren Text "&lt;".
     */
    public static function maskiere(string $wert): string
    {
        $wert = preg_replace('/\R+/u', ' ', $wert) ?? $wert;
        $wert = addcslashes($wert, '\\`*_[]#!|~');

        // Am Zeilenanfang waeren "- ", "+ " und "1. " eine Liste.
        return preg_replace('/^([-+]|\d+\.)(\s)/u', '\\\\$1$2', $wert) ?? $wert;
    }

    /**
     * @param  array<string, string>  $werte
     */
    private static function ersetze(string $text, array $werte, bool $maskiert = true): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/u',
            function (array $treffer) use ($werte, $maskiert): string {
                if (! array_key_exists($treffer[1], $werte)) {
                    // Die Pruefung beim Speichern laesst keinen fremden
                    // Platzhalter durch. Steht doch einer da, bleibt er
                    // sichtbar stehen, statt still zu verschwinden.
                    return $treffer[0];
                }

                return $maskiert ? self::maskiere($werte[$treffer[1]]) : $werte[$treffer[1]];
            },
            $text,
        );
    }

    /**
     * Leerzeilen trennen Absaetze, ein einfacher Umbruch bleibt einer.
     *
     * @return list<string>
     */
    private static function absaetze(string $text): array
    {
        $absaetze = preg_split('/\R\s*\R/u', trim($text)) ?: [];

        return array_values(array_filter(
            array_map(
                fn (string $absatz): string => (string) preg_replace('/[ \t]*\R[ \t]*/u', "  \n", trim($absatz)),
                $absaetze,
            ),
            fn (string $absatz): bool => $absatz !== '',
        ));
    }

    private static function klartext(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
