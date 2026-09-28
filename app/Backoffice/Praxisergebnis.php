<?php

declare(strict_types=1);

namespace App\Backoffice;

use App\Enums\SubscriptionAccess;
use App\Models\MonthlyClosing;

/**
 * Was eine Praxis in einem Monat einbringt und kostet (WP-34d, B19).
 *
 * **Euro-Cent, netto, hochgerechnet.** Einnahmen sind Preis mal Zustand,
 * Kosten Menge mal Satz. Was ohne Satz nicht zu rechnen war, steht in
 * `fehlendeSaetze` -- der Betrag daneben ist dann, was bekannt ist, und die
 * Zeile ist unvollstaendig, nicht null.
 *
 * Eine Praxis, keine Person: Hier steht kein Kontakt, kein Behandler.
 */
final class Praxisergebnis
{
    /**
     * @param  list<string>  $fehlendeSaetze
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly ?SubscriptionAccess $zugang,
        public readonly int $grundpreis = 0,
        public readonly int $einrichtung = 0,
        public readonly int $aufstockungen = 0,
        public readonly int $bilder = 0,
        public readonly int $servicefenster = 0,
        public readonly int $sprachmodellAgent = 0,
        public readonly int $sprachmodellAnzeigen = 0,
        public readonly int $whatsapp = 0,
        public readonly int $bildkosten = 0,
        public readonly int $zahlungsverkehr = 0,
        public readonly array $fehlendeSaetze = [],
        // Nur im laufenden Monat bekannt, fuer die Kennzahlen.
        public readonly bool $kuendigungVorgemerkt = false,
        public readonly bool $imMonatGekuendigt = false,
    ) {}

    /** Ein eingefrorener Monat (MonthlyClosing) als Ergebnis. */
    public static function ausAbschluss(MonthlyClosing $abschluss, string $uuid, string $name): self
    {
        return new self(
            uuid: $uuid,
            name: $name,
            zugang: SubscriptionAccess::tryFrom($abschluss->zugang),
            grundpreis: $abschluss->grundpreis_cents,
            einrichtung: $abschluss->einrichtung_cents,
            aufstockungen: $abschluss->aufstockungen_cents,
            bilder: $abschluss->bilder_cents,
            servicefenster: $abschluss->servicefenster_cents,
            sprachmodellAgent: $abschluss->sprachmodell_agent_cents,
            sprachmodellAnzeigen: $abschluss->sprachmodell_anzeigen_cents,
            whatsapp: $abschluss->whatsapp_cents,
            bildkosten: $abschluss->bildkosten_cents,
            zahlungsverkehr: $abschluss->zahlungsverkehr_cents,
            fehlendeSaetze: $abschluss->fehlende_saetze ?? [],
        );
    }

    /**
     * Die Spalten des Monatsabschlusses.
     *
     * @return array<string, mixed>
     */
    public function alsAbschluss(): array
    {
        return [
            'zugang' => $this->zugang->value ?? '',
            'grundpreis_cents' => $this->grundpreis,
            'einrichtung_cents' => $this->einrichtung,
            'aufstockungen_cents' => $this->aufstockungen,
            'bilder_cents' => $this->bilder,
            'servicefenster_cents' => $this->servicefenster,
            'sprachmodell_agent_cents' => $this->sprachmodellAgent,
            'sprachmodell_anzeigen_cents' => $this->sprachmodellAnzeigen,
            'whatsapp_cents' => $this->whatsapp,
            'bildkosten_cents' => $this->bildkosten,
            'zahlungsverkehr_cents' => $this->zahlungsverkehr,
            'fehlende_saetze' => $this->fehlendeSaetze === [] ? null : $this->fehlendeSaetze,
        ];
    }

    /** Zwei Zeilen zusammen -- fuer die Summe. Sie ist keine Praxis. */
    public function plus(self $andere): self
    {
        $fehlend = array_values(array_unique([...$this->fehlendeSaetze, ...$andere->fehlendeSaetze]));
        sort($fehlend);

        return new self(
            uuid: '',
            name: 'Summe',
            zugang: null,
            grundpreis: $this->grundpreis + $andere->grundpreis,
            einrichtung: $this->einrichtung + $andere->einrichtung,
            aufstockungen: $this->aufstockungen + $andere->aufstockungen,
            bilder: $this->bilder + $andere->bilder,
            servicefenster: $this->servicefenster + $andere->servicefenster,
            sprachmodellAgent: $this->sprachmodellAgent + $andere->sprachmodellAgent,
            sprachmodellAnzeigen: $this->sprachmodellAnzeigen + $andere->sprachmodellAnzeigen,
            whatsapp: $this->whatsapp + $andere->whatsapp,
            bildkosten: $this->bildkosten + $andere->bildkosten,
            zahlungsverkehr: $this->zahlungsverkehr + $andere->zahlungsverkehr,
            fehlendeSaetze: $fehlend,
        );
    }

    public function einnahmen(): int
    {
        return $this->grundpreis + $this->einrichtung + $this->aufstockungen + $this->bilder + $this->servicefenster;
    }

    public function kosten(): int
    {
        return $this->sprachmodellAgent + $this->sprachmodellAnzeigen + $this->whatsapp + $this->bildkosten + $this->zahlungsverkehr;
    }

    /** Einnahmen minus variable Kosten. Fixkosten gibt es nicht je Praxis. */
    public function deckungsbeitrag(): int
    {
        return $this->einnahmen() - $this->kosten();
    }

    public function unvollstaendig(): bool
    {
        return $this->fehlendeSaetze !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'zugang' => $this->zugang?->value,
            'zugangLabel' => $this->zugang?->label(),
            'einnahmen' => [
                'grundpreis' => $this->grundpreis,
                'einrichtung' => $this->einrichtung,
                'aufstockungen' => $this->aufstockungen,
                'bilder' => $this->bilder,
                'servicefenster' => $this->servicefenster,
            ],
            'kosten' => [
                'sprachmodellAgent' => $this->sprachmodellAgent,
                'sprachmodellAnzeigen' => $this->sprachmodellAnzeigen,
                'whatsapp' => $this->whatsapp,
                'bildkosten' => $this->bildkosten,
                'zahlungsverkehr' => $this->zahlungsverkehr,
            ],
            'einnahmenCent' => $this->einnahmen(),
            'kostenCent' => $this->kosten(),
            'deckungsbeitragCent' => $this->deckungsbeitrag(),
            'unvollstaendig' => $this->unvollstaendig(),
        ];
    }
}
