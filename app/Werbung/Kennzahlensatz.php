<?php

declare(strict_types=1);

namespace App\Werbung;

/**
 * Vier Grundwerte -- und alles, was sich daraus rechnen laesst.
 *
 * **Gerechnet, nicht gespeichert.** Meta liefert CTR, CPC und CPM mit; sie
 * mitzuschreiben hiesse zwei Zahlen fuer dieselbe Aussage zu fuehren, und die
 * weichen ab, sobald Meta rundet oder einen Tag nachtraeglich korrigiert.
 *
 * Jede Quote ist `null`, wenn ihr Nenner null ist. Nicht 0: "keine Klicks bei
 * keinen Impressionen" ist keine Klickrate von 0 Prozent, sondern gar keine.
 * Die Oberflaeche zeigt dafuer einen Strich.
 */
final class Kennzahlensatz
{
    public function __construct(
        public readonly int $ausgabenMinor = 0,
        public readonly int $impressionen = 0,
        public readonly int $klicks = 0,
        public readonly int $linkklicks = 0,
        public readonly int $leads = 0,
        public readonly int $tage = 0,
    ) {}

    public function plus(self $weitere): self
    {
        return new self(
            $this->ausgabenMinor + $weitere->ausgabenMinor,
            $this->impressionen + $weitere->impressionen,
            $this->klicks + $weitere->klicks,
            $this->linkklicks + $weitere->linkklicks,
            $this->leads + $weitere->leads,
            $this->tage + $weitere->tage,
        );
    }

    /** Klickrate in Prozent. */
    public function ctr(): ?float
    {
        return $this->impressionen === 0 ? null : $this->klicks / $this->impressionen * 100;
    }

    /** Kosten je Klick, in der kleinsten Einheit. */
    public function cpc(): ?float
    {
        return $this->klicks === 0 ? null : $this->ausgabenMinor / $this->klicks;
    }

    /** Kosten je tausend Impressionen, in der kleinsten Einheit. */
    public function cpm(): ?float
    {
        return $this->impressionen === 0 ? null : $this->ausgabenMinor / $this->impressionen * 1000;
    }

    /**
     * Kosten je Ergebnis **bei Meta**.
     *
     * Ausdruecklich nicht der "Cost per Lead" aus
     * docs/fachlogik/attribution.md: der zaehlt Anfragen, die bei der Praxis
     * ankommen, und entsteht erst mit der Zuordnung in WP-32. Dieselbe
     * Formel, eine andere Grundgesamtheit -- beide gleich zu benennen waere
     * der schnellste Weg, Vertrauen in die Zahlen zu verlieren.
     */
    public function kostenJeErgebnis(): ?float
    {
        return $this->leads === 0 ? null : $this->ausgabenMinor / $this->leads;
    }

    /**
     * @return array<string, int|float|null>
     */
    public function toArray(): array
    {
        return [
            'ausgaben' => $this->ausgabenMinor,
            'impressionen' => $this->impressionen,
            'klicks' => $this->klicks,
            'linkklicks' => $this->linkklicks,
            'leads' => $this->leads,
            'tage' => $this->tage,
            'ctr' => $this->ctr(),
            'cpc' => $this->cpc(),
            'cpm' => $this->cpm(),
            'kostenJeErgebnis' => $this->kostenJeErgebnis(),
        ];
    }
}
