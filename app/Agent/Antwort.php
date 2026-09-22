<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Was das Sprachmodell zurueckgegeben hat -- roh.
 *
 * Roh heisst: ausgewertet wird sie erst danach, und zwar gegen den Katalog
 * und die Entscheidungen des Produkts. Was hier steht, ist eine Behauptung
 * des Modells, keine Erkenntnis.
 */
final class Antwort
{
    public function __construct(
        public readonly string $inhalt,
        public readonly string $modell,
        public readonly int $eingabeTokens = 0,
        public readonly int $ausgabeTokens = 0,
    ) {}

    /**
     * Der Inhalt als Datenstruktur -- oder nichts.
     *
     * Ein Modell, das JSON liefern soll und Prosa liefert, ist kein Fehler
     * des Aufrufers und darf nichts zum Absturz bringen: der Lauf endet dann
     * als fehlgeschlagen, und ein Mensch sieht die Nachricht ohne Vorschlag.
     *
     * @return array<string, mixed>|null
     */
    public function alsStruktur(): ?array
    {
        $text = trim($this->inhalt);

        // Manche Modelle rahmen JSON in einen Codeblock. Das ist kein
        // Formatfehler, sondern der Normalfall.
        if (str_starts_with($text, '```')) {
            $text = trim((string) preg_replace('/^```[a-z]*\n|\n```$/m', '', $text));
        }

        $werte = json_decode($text, true);

        return is_array($werte) ? $werte : null;
    }
}
