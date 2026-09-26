<?php

declare(strict_types=1);

namespace App\Agent\Buchung;

use App\Models\Message;

/**
 * Was eine kurze Antwort bedeutet -- **ohne Modell**.
 *
 * "Ja", "die zweite", "10:30". Ein Sprachmodell dafuer zu fragen, waere ein
 * Aufruf fuer eine Entscheidung, die eine Wortliste zuverlaessiger trifft --
 * und eine Stelle mehr, an der eine fremde Nachricht etwas ausloesen koennte
 * (Regel 5).
 *
 * Im Zweifel **nein**: eine Zustimmung, die nicht dasteht, wird nicht
 * angenommen. Das kostet eine Rueckfrage; die Alternative kostet einen
 * Termin, den niemand wollte, oder eine Einwilligung, die niemand gab.
 */
final class Antwortdeutung
{
    /** Ist das ein Ja? */
    public function zustimmung(Message $nachricht): bool
    {
        $text = $this->text($nachricht);

        foreach ((array) config('mrs.agent.affirmations', []) as $wort) {
            if (is_string($wort) && preg_match('/(^|\W)'.preg_quote(mb_strtolower($wort), '/').'(\W|$)/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Ist das ein Nein? */
    public function ablehnung(Message $nachricht): bool
    {
        $text = $this->text($nachricht);

        foreach ((array) config('mrs.agent.negations', []) as $wort) {
            if (is_string($wort) && preg_match('/(^|\W)'.preg_quote(mb_strtolower($wort), '/').'(\W|$)/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ist es egal, bei wem? Dann gibt es keinen Behandlerwunsch.
     *
     * Wie Ja und Nein eine Wortliste, kein Modellaufruf: "wer frei ist" ist
     * eine Antwort, die niemand anders meint.
     */
    public function gleichgueltig(Message $nachricht): bool
    {
        $text = $this->text($nachricht);

        foreach ((array) config('mrs.agent.indifference', []) as $wort) {
            if (is_string($wort) && preg_match('/(^|\W)'.preg_quote(mb_strtolower($wort), '/').'(\W|$)/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Welchen der angebotenen Termine meint die Antwort?
     *
     * Erkannt werden die Nummer ("2", "die zweite") und die Uhrzeit
     * ("10:30"). Alles andere ist keine Wahl -- und wird auch nicht als eine
     * behandelt.
     *
     * @param  list<string>  $angebote  ISO-Zeitpunkte in der Reihenfolge des Angebots
     */
    public function wahl(Message $nachricht, array $angebote): ?string
    {
        if ($angebote === []) {
            return null;
        }

        $text = $this->text($nachricht);

        // Die Uhrzeit ist die genauere Angabe und geht deshalb vor.
        if (preg_match('/\b(\d{1,2})[:.](\d{2})\b/', $text, $treffer) === 1) {
            $gesucht = sprintf('%02d:%02d', (int) $treffer[1], (int) $treffer[2]);

            foreach ($angebote as $zeitpunkt) {
                if (str_contains($zeitpunkt, 'T'.$gesucht) || str_contains($this->ortszeit($zeitpunkt), $gesucht)) {
                    return $zeitpunkt;
                }
            }
        }

        $ordinale = ['erste' => 1, 'ersten' => 1, 'zweite' => 2, 'zweiten' => 2, 'dritte' => 3, 'dritten' => 3];

        foreach ($ordinale as $wort => $stelle) {
            if (str_contains($text, $wort)) {
                return $angebote[$stelle - 1] ?? null;
            }
        }

        if (preg_match('/(^|\W)([1-9])(\W|$)/', $text, $treffer) === 1) {
            return $angebote[((int) $treffer[2]) - 1] ?? null;
        }

        return null;
    }

    /** Die Ortszeit eines angebotenen Zeitpunkts, fuer den Vergleich. */
    private function ortszeit(string $iso): string
    {
        return $iso;
    }

    private function text(Message $nachricht): string
    {
        return mb_strtolower(trim((string) $nachricht->body));
    }
}
