<?php

declare(strict_types=1);

namespace App\Attribution;

use App\Enums\AttributionModel;
use App\Enums\Aufschluesselung;
use App\Enums\InsightLevel;
use App\Enums\LeadStatus;
use App\Models\AdCampaign;
use App\Models\AdInsight;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\Treatment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Die Kette von der Anzeige bis zum Umsatz, in Zahlen.
 *
 * **Die Definitionen stehen in `docs/fachlogik/attribution.md`** und hier an
 * genau einer Stelle. Abweichende Auslegung in Berichten ist der schnellste
 * Weg, Vertrauen in die Zahlen zu verlieren.
 *
 * **Gewonnen heisst erschienen**, nicht gebucht: ein Termin, den niemand
 * wahrnimmt, zaehlt nicht als Abschluss -- sonst misst der ROAS Absichten
 * statt Umsatz.
 */
final class Auswertung
{
    /**
     * @return list<Auswertungszeile>
     */
    public function zeilen(
        CarbonImmutable $von,
        CarbonImmutable $bis,
        Aufschluesselung $nach = Aufschluesselung::Kampagne,
        ?AttributionModel $modell = null,
    ): array {
        $termine = $this->termine($von, $bis, $modell);
        $ausgaben = $nach->traegtKosten() ? $this->ausgabenJeKampagne($von, $bis) : [];
        $bezeichnungen = $this->bezeichnungen($nach);

        /** @var array<string, list<Appointment>> $gruppen */
        $gruppen = [];

        foreach ($termine as $termin) {
            $schluessel = $this->schluessel($termin, $nach);
            $gruppen[$schluessel][] = $termin;
        }

        // Kampagnen mit Ausgaben, aber ohne einen einzigen Termin, gehoeren
        // in die Liste: eine Kampagne, die Geld kostet und nichts bringt, ist
        // die wichtigste Zeile darin.
        if ($nach->traegtKosten()) {
            foreach (array_keys($ausgaben) as $kennung) {
                $gruppen[$kennung] ??= [];
            }
        }

        $zeilen = [];

        foreach ($gruppen as $schluessel => $gruppe) {
            $zeilen[] = $this->zeile(
                $schluessel,
                $bezeichnungen[$schluessel] ?? $this->unbekannt($nach),
                $gruppe,
                // **Keine Zeile bei Meta heisst unbekannt, nicht null Euro.**
                // "0,00 EUR je Anfrage" saehe aus, als waeren diese Anfragen
                // umsonst gekommen -- dieselbe Unterscheidung wie in WP-28
                // zwischen "keine Auslieferung" und "Nullen".
                $nach->traegtKosten() ? ($ausgaben[$schluessel] ?? null) : null,
            );
        }

        usort($zeilen, fn (Auswertungszeile $a, Auswertungszeile $b): int => $b->leads <=> $a->leads);

        return $zeilen;
    }

    /**
     * Die Summe ueber alles -- aus denselben Zeilen.
     *
     * Nie eine zweite Abfrage: sonst weichen Gesamtwert und Aufschluesselung
     * voneinander ab, und niemand kann sagen, welche der beiden stimmt.
     *
     * @param  list<Auswertungszeile>  $zeilen
     */
    public function summe(array $zeilen): Auswertungszeile
    {
        $ausgaben = null;

        foreach ($zeilen as $zeile) {
            if ($zeile->ausgabenMinor !== null) {
                $ausgaben = ($ausgaben ?? 0) + $zeile->ausgabenMinor;
            }
        }

        return new Auswertungszeile(
            schluessel: 'gesamt',
            bezeichnung: 'Gesamt',
            leads: array_sum(array_map(fn (Auswertungszeile $z): int => $z->leads, $zeilen)),
            gebucht: array_sum(array_map(fn (Auswertungszeile $z): int => $z->gebucht, $zeilen)),
            erschienen: array_sum(array_map(fn (Auswertungszeile $z): int => $z->erschienen, $zeilen)),
            nichtErschienen: array_sum(array_map(fn (Auswertungszeile $z): int => $z->nichtErschienen, $zeilen)),
            abschluesse: array_sum(array_map(fn (Auswertungszeile $z): int => $z->abschluesse, $zeilen)),
            umsatzCents: array_sum(array_map(fn (Auswertungszeile $z): int => $z->umsatzCents, $zeilen)),
            ausgabenMinor: $ausgaben,
        );
    }

    /**
     * Die Summe ueber das, was einer Kampagne zugeordnet ist.
     *
     * **Cost per Lead teilt nicht durch alle Anfragen.**
     * `docs/fachlogik/attribution.md` sagt es genau: "Leads: Anzahl `leads`
     * **mit zugeordneter Kampagne** im Zeitraum". Wer die Anfragen ohne
     * Quelle mitzaehlt, druckt die Kosten je Anfrage kuenstlich -- und zwar
     * in die Richtung, die schmeichelt.
     *
     * @param  list<Auswertungszeile>  $zeilen
     */
    public function zugeordnet(array $zeilen): Auswertungszeile
    {
        return $this->summe(array_values(array_filter(
            $zeilen,
            fn (Auswertungszeile $z): bool => $z->schluessel !== 'unbekannt',
        )));
    }

    /**
     * @param  list<Appointment>  $gruppe
     */
    private function zeile(string $schluessel, string $bezeichnung, array $gruppe, ?int $ausgaben): Auswertungszeile
    {
        $kontakte = [];
        $erschienen = 0;
        $nichtErschienen = 0;

        foreach ($gruppe as $termin) {
            $kontakte[bin2hex((string) $termin->getAttributes()['contact_id'])] = true;

            $erschienen += $termin->status->value === 'attended' ? 1 : 0;
            $nichtErschienen += $termin->status->value === 'no_show' ? 1 : 0;
        }

        $leads = $this->leadsZu(array_keys($kontakte));

        return new Auswertungszeile(
            schluessel: $schluessel,
            bezeichnung: $bezeichnung,
            leads: $leads->count(),

            // "Beratungen gebucht": pending, confirmed, attended. Eine
            // Absage ist keine Buchung mehr.
            gebucht: count(array_filter(
                $gruppe,
                fn (Appointment $t): bool => in_array($t->status->value, ['pending', 'confirmed', 'attended'], true),
            )),

            erschienen: $erschienen,
            nichtErschienen: $nichtErschienen,

            // **Gewonnen heisst erschienen.**
            abschluesse: $leads->where('status', LeadStatus::Won)->count(),

            umsatzCents: $this->umsatz($leads),
            ausgabenMinor: $ausgaben,
            speedToLead: $this->median(array_values(array_filter(
                $leads->pluck('first_response_seconds')->all(),
                is_int(...),
            ))),
        );
    }

    /**
     * Die Termine des Zeitraums, mit ihrer Zuordnung.
     *
     * **Das Modell wirkt hier.** Der Snapshot haelt die Zuordnung nach dem
     * Vorgabemodell fest (D13); wer ein anderes ansehen will, rechnet es aus
     * den Beruehrungen neu.
     *
     * @return list<Appointment>
     */
    private function termine(CarbonImmutable $von, CarbonImmutable $bis, ?AttributionModel $modell): array
    {
        /** @var list<Appointment> $termine */
        $termine = Appointment::query()
            ->whereBetween('starts_at', [$von, $bis])
            ->with('contact')
            ->get()
            ->values()
            ->all();

        if ($modell === null) {
            return $termine;
        }

        // Ein anderes Modell heisst: die Kampagne neu bestimmen. Der
        // eingefrorene Stand bleibt unangetastet -- er ist die Aussage von
        // damals.
        $zuordnung = app(Zuordnung::class);

        foreach ($termine as $termin) {
            $touch = $zuordnung->touch($termin->contact, $termin->created_at ?? $von, $modell);
            $termin->attribution_campaign_id = $touch?->campaign_external_id;
        }

        return $termine;
    }

    /**
     * @param  list<string>  $kontakteHex
     * @return Collection<int, Lead>
     */
    private function leadsZu(array $kontakteHex): Collection
    {
        if ($kontakteHex === []) {
            /** @var Collection<int, Lead> */
            return collect();
        }

        return Lead::query()
            ->whereIn('contact_id', array_map(hex2bin(...), $kontakteHex))
            ->get();
    }

    /**
     * **Ein Schaetzwert, kein abgerechneter Betrag.**
     *
     * `avg_revenue_cents` ist ein Durchschnitt aus dem Katalog. Das Produkt
     * nennt ihn durchgaengig "zugeordneter Schaetzwert" -- eine Zahl, die
     * genauer aussieht, als sie ist, kostet beim ersten Nachrechnen das
     * Vertrauen in alle anderen.
     *
     * @param  Collection<int, Lead>  $leads
     */
    private function umsatz(Collection $leads): int
    {
        $behandlungen = Treatment::query()->get()->keyBy(fn (Treatment $b): string => bin2hex((string) $b->getKey()));

        return (int) $leads
            ->where('status', LeadStatus::Won)
            ->sum(function (Lead $lead) use ($behandlungen): int {
                $kennung = $lead->getAttributes()['treatment_id'] ?? null;

                if (! is_string($kennung)) {
                    return 0;
                }

                $behandlung = $behandlungen->get(bin2hex($kennung));

                return $behandlung instanceof Treatment ? $behandlung->avg_revenue_cents : 0;
            });
    }

    /**
     * @return array<string, int>
     */
    private function ausgabenJeKampagne(CarbonImmutable $von, CarbonImmutable $bis): array
    {
        $zeilen = AdInsight::query()
            ->ebene(InsightLevel::Campaign)
            ->zeitraum($von, $bis)
            ->select('external_id')
            ->selectRaw('SUM(spend_minor) AS ausgaben')
            ->groupBy('external_id')
            ->get();

        $summe = [];

        foreach ($zeilen as $zeile) {
            $summe[(string) $zeile->getAttributes()['external_id']] = (int) $zeile->getAttributes()['ausgaben'];
        }

        return $summe;
    }

    private function schluessel(Appointment $termin, Aufschluesselung $nach): string
    {
        $wert = match ($nach) {
            Aufschluesselung::Kampagne => $termin->attribution_campaign_id,
            Aufschluesselung::Behandlung => $this->hex($termin->appointmentType->getAttributes()['treatment_id'] ?? null),
            Aufschluesselung::Behandler => $this->hex($termin->getAttributes()['practitioner_id'] ?? null),
            Aufschluesselung::Standort => $this->hex($termin->getAttributes()['location_id'] ?? null),
        };

        return is_string($wert) && $wert !== '' ? $wert : 'unbekannt';
    }

    private function hex(mixed $roh): ?string
    {
        return is_string($roh) ? bin2hex($roh) : null;
    }

    /**
     * @return array<string, string>
     */
    private function bezeichnungen(Aufschluesselung $nach): array
    {
        return match ($nach) {
            Aufschluesselung::Kampagne => AdCampaign::query()
                ->get()
                ->mapWithKeys(fn (AdCampaign $k): array => [$k->external_id => (string) ($k->name ?? $k->external_id)])
                ->all(),

            Aufschluesselung::Behandlung => Treatment::query()
                ->get()
                ->mapWithKeys(fn (Treatment $b): array => [bin2hex((string) $b->getKey()) => $b->name])
                ->all(),

            Aufschluesselung::Behandler => Practitioner::query()
                ->get()
                ->mapWithKeys(fn (Practitioner $b): array => [bin2hex((string) $b->getKey()) => $b->name()])
                ->all(),

            Aufschluesselung::Standort => Location::query()
                ->get()
                ->mapWithKeys(fn (Location $o): array => [bin2hex((string) $o->getKey()) => $o->name])
                ->all(),
        };
    }

    /**
     * **"Quelle unbekannt", nicht "Direktzugriff".** Der Unterschied ist der
     * zwischen "wir wissen es nicht" und "es kam von nirgendwo"
     * (attribution.md, Testfall 9).
     */
    private function unbekannt(Aufschluesselung $nach): string
    {
        return $nach === Aufschluesselung::Kampagne ? 'Quelle unbekannt' : 'Ohne Angabe';
    }

    /**
     * Der Median, nicht der Mittelwert.
     *
     * Ein einzelner Vorgang, der drei Tage liegen blieb, zieht den Mittelwert
     * so weit, dass die Zahl nichts mehr ueber den Alltag sagt.
     *
     * @param  list<int>  $werte
     */
    private function median(array $werte): ?int
    {
        if ($werte === []) {
            return null;
        }

        sort($werte);
        $mitte = intdiv(count($werte), 2);

        return count($werte) % 2 === 1
            ? $werte[$mitte]
            : (int) round(($werte[$mitte - 1] + $werte[$mitte]) / 2);
    }
}
