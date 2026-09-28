<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Vorlagen;

use App\Enums\Mailart;
use App\Enums\Mailfeld;
use App\Enums\Platzhalter;

/**
 * Prueft ein Feld einer Vorlage, bevor es gespeichert wird (C17).
 *
 * **Abgelehnt wird am Feld, nicht in der Warteschlange.** Eine Vorlage, die
 * erst beim Versand scheitert, scheitert bei einer Patientin.
 *
 * Erlaubt ist, was eine Mail braucht: Absaetze, **fett** und Links mit
 * `https`. Kein HTML, keine Bilder, keine Ueberschriften, kein Code -- das
 * Aussehen setzt der Rahmen, nicht der Text.
 */
final class Textpruefung
{
    private const PLATZHALTER = '/\{([^{}\s]*)\}/u';

    /**
     * @return list<string> Meldungen, leer heisst "in Ordnung"
     */
    public function pruefe(Mailart $art, Mailfeld $feld, string $text): array
    {
        $meldungen = [];

        if ($feld === Mailfeld::Betreff && trim($text) === '') {
            return ['Der Betreff darf nicht leer sein.'];
        }

        if (mb_strlen($text) > $feld->hoechstlaenge()) {
            $meldungen[] = "Höchstens {$feld->hoechstlaenge()} Zeichen.";
        }

        if ($feld->istEinzeilig() && preg_match('/\R/u', $text) === 1) {
            $meldungen[] = "{$feld->label()} steht in einer Zeile.";
        }

        $meldungen = [...$meldungen, ...$this->platzhalter($art, $feld, $text)];

        if (preg_match('/<\s*[a-z!\/?]/iu', $text) === 1) {
            $meldungen[] = 'HTML ist nicht erlaubt.';
        }

        if (str_contains($text, '![')) {
            $meldungen[] = 'Bilder sind nicht erlaubt.';
        }

        if (str_contains($text, '`')) {
            $meldungen[] = 'Code ist nicht erlaubt.';
        }

        if (preg_match('/^\s{0,3}#/mu', $text) === 1) {
            $meldungen[] = 'Überschriften sind nicht erlaubt.';
        }

        return [...$meldungen, ...$this->links($feld, $text)];
    }

    /**
     * Die Meldungen fuer alle Felder eines Texts, nach Spalte.
     *
     * @return array<string, list<string>>
     */
    public function pruefeAlle(Mailart $art, Mailtext $text): array
    {
        $fehler = [];

        foreach (Mailfeld::cases() as $feld) {
            $meldungen = $this->pruefe($art, $feld, $text->feld($feld));

            if ($meldungen !== []) {
                $fehler[$feld->value] = $meldungen;
            }
        }

        return $fehler;
    }

    /**
     * @return list<string>
     */
    private function platzhalter(Mailart $art, Mailfeld $feld, string $text): array
    {
        preg_match_all(self::PLATZHALTER, $text, $treffer);

        $erlaubt = array_map(fn (Platzhalter $platzhalter): string => $platzhalter->value, $art->platzhalter($feld));
        $unbekannt = array_values(array_unique(array_diff($treffer[1], $erlaubt)));

        if ($unbekannt === []) {
            return [];
        }

        $liste = implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $unbekannt));

        return [$erlaubt === []
            ? "Hier ist kein Platzhalter erlaubt ({$liste})."
            : "Nicht erlaubt: {$liste}. Erlaubt sind ".implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $erlaubt)).'.'];
    }

    /**
     * Links nur mit `https`, im Betreff gar keine.
     *
     * @return list<string>
     */
    private function links(Mailfeld $feld, string $text): array
    {
        $ziele = [];

        preg_match_all('/\[[^\]]*\]\(\s*([^)\s]*)[^)]*\)/u', $text, $markdown);
        $ziele = [...$ziele, ...$markdown[1]];

        // Nackte Adressen: CommonMark macht keine Links daraus, das
        // Mailprogramm der Patientin schon.
        preg_match_all('/(?<![\(\w])((?:[a-z][a-z0-9+.-]*:\/\/|www\.)[^\s)]+)/iu', $text, $nackt);
        $ziele = [...$ziele, ...$nackt[1]];

        if ($ziele === []) {
            return [];
        }

        if ($feld === Mailfeld::Betreff) {
            return ['Der Betreff enthält keine Links.'];
        }

        foreach ($ziele as $ziel) {
            if (! str_starts_with(mb_strtolower($ziel), 'https://') || mb_strlen($ziel) <= 8) {
                return ['Links nur mit https://.'];
            }
        }

        return [];
    }
}
