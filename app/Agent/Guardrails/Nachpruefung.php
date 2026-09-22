<?php

declare(strict_types=1);

namespace App\Agent\Guardrails;

use App\Enums\GuardrailHit;
use App\Models\Treatment;

/**
 * Die Nachpruefung der erzeugten Antwort (docs/fachlogik/agent.md, Schritt 7).
 *
 * **Die zweite Verteidigungslinie.** Selbst wenn die Erzeugung uebersteuert
 * wurde -- durch eine Anweisung in einer Nachricht, durch ein Modell, das
 * sich irrt --, wird eine abweichende Antwort hier abgefangen. Beide Ebenen
 * sind noetig, nicht eine davon.
 *
 * Sie prueft **Text**, nicht Absicht: was hier durchgeht, darf hinaus; was
 * haengenbleibt, sieht ein Mensch. Falschausloesungen kosten eine Uebergabe,
 * eine durchgelassene Preiszusage kostet mehr.
 */
final class Nachpruefung
{
    /**
     * @return list<GuardrailHit> Leer heisst: die Antwort darf hinaus
     */
    public function pruefe(string $antwort): array
    {
        $text = mb_strtolower($antwort);
        $treffer = [];

        if ($this->traegtWortstamm($text, (array) config('mrs.agent.discount_stems', []))) {
            $treffer[] = GuardrailHit::Discount;
        }

        if ($this->traegtWortstamm($text, (array) config('mrs.agent.promise_stems', []))) {
            $treffer[] = GuardrailHit::Promise;
        }

        if ($this->medizinischeAussage($text)) {
            $treffer[] = GuardrailHit::MedicalStatement;
        }

        if ($this->fremderPreis($antwort)) {
            $treffer[] = GuardrailHit::PriceOutsideCatalog;
        }

        if ($this->fremdeBehandlung($text)) {
            $treffer[] = GuardrailHit::UnknownTreatment;
        }

        if ($this->fremdsprachig($text)) {
            $treffer[] = GuardrailHit::ForeignLanguage;
        }

        return $treffer;
    }

    /**
     * @param  array<int, mixed>  $staemme
     */
    private function traegtWortstamm(string $text, array $staemme): bool
    {
        foreach ($staemme as $stamm) {
            if (is_string($stamm) && $stamm !== '' && str_contains($text, mb_strtolower($stamm))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Eine Aussage zu Eignung, Risiken, Wirkstoffen, Dosierung, Heilung oder
     * Nachsorge.
     *
     * Bewusst weit gefasst. Der Agent ist eine Empfangskraft: er hat zu
     * diesen Dingen nichts zu sagen, auch nichts Harmloses.
     */
    private function medizinischeAussage(string $text): bool
    {
        return $this->traegtWortstamm($text, (array) config('mrs.agent.medical_stems', []));
    }

    /**
     * **Jede Zahl mit Waehrungsbezug wird gegen den Katalog geprueft.**
     *
     * Ein Preis, den niemand hinterlegt hat, ist erfunden -- gleich, wie
     * plausibel er klingt (Entscheidung G5).
     */
    private function fremderPreis(string $antwort): bool
    {
        preg_match_all('/(\d{1,3}(?:[.\s]\d{3})*(?:,\d{2})?)\s*(?:€|EUR\b|Euro\b)/iu', $antwort, $treffer);

        if ($treffer[1] === []) {
            return false;
        }

        $erlaubt = $this->katalogpreise();

        foreach ($treffer[1] as $gefunden) {
            if (! in_array($this->cents($gefunden), $erlaubt, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ein Behandlungsname, den der Katalog nicht kennt -- aber nur, wenn er
     * wie einer aussieht.
     *
     * Geprueft wird gegen die **Sperrliste** der bekannten Namen: was im
     * Katalog steht, ist erlaubt. Ein erfundener Name faellt nur auf, wenn
     * er einem bekannten aehnelt -- deshalb prueft diese Regel die Nennung
     * typischer Leistungsbegriffe, die **nicht** im Katalog stehen.
     */
    private function fremdeBehandlung(string $text): bool
    {
        $katalog = array_map(
            fn (string $name): string => mb_strtolower($name),
            Treatment::aktiveNamen(),
        );

        foreach ((array) config('mrs.agent.treatment_terms', []) as $begriff) {
            if (! is_string($begriff) || $begriff === '') {
                continue;
            }

            $begriff = mb_strtolower($begriff);

            if (! str_contains($text, $begriff)) {
                continue;
            }

            // Steht der Begriff in einem Katalognamen, ist er erlaubt.
            $imKatalog = false;

            foreach ($katalog as $name) {
                if (str_contains($name, $begriff)) {
                    $imKatalog = true;

                    break;
                }
            }

            if (! $imKatalog) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Antwort muss Deutsch sein (Entscheidung P7).
     *
     * Geprueft wird ueber gebraeuchliche deutsche Woerter statt ueber eine
     * Spracherkennung: die waere eine Abhaengigkeit mehr fuer eine Frage, die
     * sich so zuverlaessig genug beantworten laesst.
     */
    private function fremdsprachig(string $text): bool
    {
        if (mb_strlen(trim($text)) < 25) {
            // Zu kurz fuer eine Aussage. "Gern!" ist kein Englisch.
            return false;
        }

        foreach ((array) config('mrs.agent.german_markers', []) as $wort) {
            if (is_string($wort) && preg_match('/\b'.preg_quote($wort, '/').'\b/u', $text) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Alle Preise des Katalogs, in Cent.
     *
     * @return list<int>
     */
    private function katalogpreise(): array
    {
        $preise = [];

        foreach (Treatment::query()->aktiv()->get() as $behandlung) {
            foreach ([$behandlung->price_from_cents, $behandlung->price_to_cents] as $wert) {
                if ($wert !== null) {
                    $preise[] = $wert;
                }
            }
        }

        return array_values(array_unique($preise));
    }

    private function cents(string $betrag): int
    {
        $sauber = str_replace([' ', '.'], '', $betrag);
        $sauber = str_replace(',', '.', $sauber);

        return (int) round(((float) $sauber) * 100);
    }
}
