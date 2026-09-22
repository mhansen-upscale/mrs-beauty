<?php

declare(strict_types=1);

namespace App\Attribution;

/**
 * Eine Zeile der Auswertung -- und alles, was sich daraus rechnen laesst.
 *
 * **Gerechnet, nicht gespeichert.** Dieselbe Haltung wie bei den
 * Werbekennzahlen aus WP-28: eine abgelegte Quote ist die zweite Zahl fuer
 * dieselbe Aussage.
 *
 * Jede Quote ist `null`, wenn ihr Nenner null ist. Nicht 0: "keine
 * Erschienenen bei keinen Terminen" ist keine Show-Rate von 0 Prozent,
 * sondern gar keine. Die Oberflaeche zeigt dafuer einen Strich.
 */
final class Auswertungszeile
{
    public function __construct(
        public readonly string $schluessel,
        public readonly string $bezeichnung,
        public readonly int $leads = 0,
        public readonly int $gebucht = 0,
        public readonly int $erschienen = 0,
        public readonly int $nichtErschienen = 0,
        public readonly int $abschluesse = 0,
        public readonly int $umsatzCents = 0,

        /**
         * Ausgaben in kleinster Einheit -- oder `null`.
         *
         * **Meta rechnet je Kampagne ab, nicht je Behandler.** Bei jeder
         * anderen Aufschluesselung bleibt die Kostenseite leer, und zwar
         * sichtbar: eine gerechnete Verteilung waere erfunden, und erfundene
         * Zahlen sind schlimmer als fehlende.
         */
        public readonly ?int $ausgabenMinor = null,

        /** Median in Sekunden, nicht Mittelwert. */
        public readonly ?int $speedToLead = null,
    ) {}

    /** Erschienen geteilt durch gebucht. */
    public function showRate(): ?float
    {
        return $this->gebucht === 0 ? null : $this->erschienen / $this->gebucht * 100;
    }

    public function noShowQuote(): ?float
    {
        $summe = $this->erschienen + $this->nichtErschienen;

        return $summe === 0 ? null : $this->nichtErschienen / $summe * 100;
    }

    public function costPerLead(): ?float
    {
        return $this->ausgabenMinor === null || $this->leads === 0
            ? null
            : $this->ausgabenMinor / $this->leads;
    }

    public function costPerConsult(): ?float
    {
        return $this->ausgabenMinor === null || $this->gebucht === 0
            ? null
            : $this->ausgabenMinor / $this->gebucht;
    }

    /** Ausgaben je Abschluss. */
    public function cac(): ?float
    {
        return $this->ausgabenMinor === null || $this->abschluesse === 0
            ? null
            : $this->ausgabenMinor / $this->abschluesse;
    }

    /**
     * Zugeordneter Umsatz geteilt durch Ausgaben.
     *
     * Ohne Ausgaben keine Aussage -- nicht "unendlich" und nicht null.
     */
    public function roas(): ?float
    {
        return $this->ausgabenMinor === null || $this->ausgabenMinor === 0
            ? null
            : $this->umsatzCents / $this->ausgabenMinor;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schluessel' => $this->schluessel,
            'bezeichnung' => $this->bezeichnung,
            'leads' => $this->leads,
            'gebucht' => $this->gebucht,
            'erschienen' => $this->erschienen,
            'nichtErschienen' => $this->nichtErschienen,
            'abschluesse' => $this->abschluesse,
            'umsatz' => $this->umsatzCents,
            'ausgaben' => $this->ausgabenMinor,
            'showRate' => $this->showRate(),
            'noShowQuote' => $this->noShowQuote(),
            'costPerLead' => $this->costPerLead(),
            'costPerConsult' => $this->costPerConsult(),
            'cac' => $this->cac(),
            'roas' => $this->roas(),
            'speedToLead' => $this->speedToLead,
        ];
    }
}
