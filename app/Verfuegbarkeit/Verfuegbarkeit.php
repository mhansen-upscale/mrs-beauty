<?php

declare(strict_types=1);

namespace App\Verfuegbarkeit;

use App\Models\Absence;
use App\Models\AppointmentSlot;
use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Practitioner;
use Carbon\CarbonImmutable;

/**
 * Beantwortet die eine Frage des Pakets: welche Slots sind buchbar?
 *
 * Die elf Bedingungen aus docs/fachlogik/verfuegbarkeit.md verteilen sich so:
 *
 * | V1, V2, V3 | Arbeitszeit, Abwesenheit, Schliesszeit | WP-08 |
 * | V4, V5, V6 | externer Blocker, Termin, gueltiger Hold | der Scope `frei` |
 * | V7, V8     | Behandler- und Standortfreigabe         | WP-09 |
 * | V9         | Vorlaufzeit                             | WP-09 |
 * | V10        | Buchungshorizont                        | hier |
 * | V11        | volle Dauer lueckenlos frei             | hier |
 *
 * V1 steckt in der Materialisierung: es gibt nur Slots, wo jemand arbeitet.
 * V2 und V3 werden **zur Abfragezeit** geprueft und nicht in die Slots
 * eingebrannt -- eine Krankmeldung kommt nach der Erzeugung, und ein
 * gestrichener Urlaub soll die Slots zurueckbringen, ohne dass jemand einen
 * Job anwirft.
 */
final class Verfuegbarkeit
{
    /**
     * Freie Startzeiten fuer eine Terminart.
     *
     * @return list<Slotvorschlag>
     */
    public function freieStartzeiten(
        AppointmentType $art,
        CarbonImmutable $von,
        CarbonImmutable $bis,
        ?Practitioner $nurBehandler = null,
        ?Location $nurStandort = null,
        ?CarbonImmutable $jetzt = null,
    ): array {
        $jetzt ??= CarbonImmutable::now();

        if (! $art->is_active) {
            return [];
        }

        // V10 -- Buchungshorizont. Weiter als materialisiert wird, kann
        // niemand buchen.
        $horizont = $jetzt->addDays((int) config('mrs.booking.horizon_days', 90));
        $bis = $bis->min($horizont);

        if ($bis <= $von) {
            return [];
        }

        $schritt = (int) config('mrs.booking.slot_minutes', 5);
        $benoetigt = (int) ceil($art->belegteDauer() / $schritt);

        $vorschlaege = [];

        foreach ($this->paare($art, $nurBehandler, $nurStandort) as [$behandler, $standort]) {
            $sperren = $this->sperren($behandler, $standort, $von, $bis->addMinutes($art->belegteDauer()));

            /** @var list<CarbonImmutable> $freie */
            $freie = AppointmentSlot::query()
                ->frei()
                ->where('practitioner_id', $behandler->getKey())
                ->where('location_id', $standort->getKey())
                ->where('starts_at', '>=', $von)
                ->where('starts_at', '<', $bis->addMinutes($art->belegteDauer()))
                ->orderBy('starts_at')
                ->pluck('starts_at')
                ->map(fn (mixed $zeit): CarbonImmutable => CarbonImmutable::parse((string) $zeit, 'UTC'))
                ->values()
                ->all();
            foreach ($this->strecken($freie, $benoetigt, $schritt) as $beginn) {
                $vorschlag = Slotvorschlag::ab($art, $behandler, $standort, $beginn);

                if ($vorschlag->startsAt < $von || $vorschlag->startsAt >= $bis) {
                    continue;
                }

                // V9 -- Vorlaufzeit
                if (! $art->istBuchbarAm($vorschlag->startsAt, $jetzt)) {
                    continue;
                }

                // V2, V3 -- die **ganze** belegte Strecke muss frei von
                // Abwesenheit und Schliesszeit sein, nicht nur ihr Beginn.
                if ($this->ueberschneidetSperre($sperren, $vorschlag->blockedFrom, $vorschlag->blockedUntil)) {
                    continue;
                }

                $vorschlaege[] = $vorschlag;
            }
        }

        usort($vorschlaege, fn (Slotvorschlag $a, Slotvorschlag $b): int => $a->startsAt <=> $b->startsAt);

        return $vorschlaege;
    }

    /**
     * Ist dieser konkrete Vorschlag noch buchbar?
     *
     * Gebraucht von der Warteliste (WP-25), die keine Slots sucht, sondern
     * einen bestimmten prueft.
     */
    public function istBuchbar(Slotvorschlag $vorschlag, ?CarbonImmutable $jetzt = null): bool
    {
        $gefunden = $this->freieStartzeiten(
            art: $vorschlag->art,
            von: $vorschlag->startsAt,
            bis: $vorschlag->startsAt->addMinute(),
            nurBehandler: $vorschlag->behandler,
            nurStandort: $vorschlag->standort,
            jetzt: $jetzt,
        );

        return $gefunden !== [];
    }

    /**
     * Liegt in diesem Zeitraum eine Abwesenheit oder eine Schliesszeit?
     * (V2 und V3)
     *
     * Gebraucht von der internen Terminverwaltung (WP-11): sie prueft die
     * Bedingungen einzeln, weil sie einige davon uebersteuern darf und andere
     * nicht.
     */
    public function istGesperrt(
        Practitioner $behandler,
        Location $standort,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): bool {
        return $this->ueberschneidetSperre(
            $this->sperren($behandler, $standort, $von, $bis),
            $von,
            $bis,
        );
    }

    /**
     * Die Paare aus Behandler und Standort, die diese Terminart anbieten
     * (V7 und V8).
     *
     * @return list<array{Practitioner, Location}>
     */
    private function paare(AppointmentType $art, ?Practitioner $nurBehandler, ?Location $nurStandort): array
    {
        $behandler = $art->practitioners()->where('practitioners.is_active', true)->get();
        $standorte = $art->locations()->where('locations.is_active', true)->get();

        if ($nurBehandler instanceof Practitioner) {
            $behandler = $behandler->where('id', $nurBehandler->getKey());
        }

        if ($nurStandort instanceof Location) {
            $standorte = $standorte->where('id', $nurStandort->getKey());
        }

        $paare = [];

        foreach ($behandler as $person) {
            foreach ($standorte as $ort) {
                $paare[] = [$person, $ort];
            }
        }

        return $paare;
    }

    /**
     * Abwesenheiten und Schliesszeiten im Zeitraum, einmal geladen.
     *
     * @return list<array{CarbonImmutable, CarbonImmutable}>
     */
    private function sperren(
        Practitioner $behandler,
        Location $standort,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): array {
        $abwesend = Absence::query()
            ->where('practitioner_id', $behandler->getKey())
            ->where('starts_at', '<', $bis)
            ->where('ends_at', '>', $von)
            ->get()
            ->map(fn (Absence $a): array => [$a->starts_at, $a->ends_at]);

        $geschlossen = LocationClosure::query()
            ->where('location_id', $standort->getKey())
            ->where('starts_at', '<', $bis)
            ->where('ends_at', '>', $von)
            ->get()
            ->map(fn (LocationClosure $s): array => [$s->starts_at, $s->ends_at]);

        /** @var list<array{CarbonImmutable, CarbonImmutable}> */
        return $abwesend->concat($geschlossen)->values()->all();
    }

    /**
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $sperren
     */
    private function ueberschneidetSperre(array $sperren, CarbonImmutable $von, CarbonImmutable $bis): bool
    {
        foreach ($sperren as [$beginn, $ende]) {
            if ($beginn < $bis && $ende > $von) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Beginne aller lueckenlosen Strecken der geforderten Laenge (V11).
     *
     * Es genuegt nicht, dass der Startslot frei ist -- ein Termin ueber 45
     * Minuten braucht neun freie Zeilen am Stueck, und die Ruestzeit zaehlt
     * mit.
     *
     * @param  list<CarbonImmutable>  $freie
     * @return list<CarbonImmutable>
     */
    private function strecken(array $freie, int $benoetigt, int $schritt): array
    {
        $anzahl = count($freie);
        $beginne = [];

        for ($i = 0; $i + $benoetigt <= $anzahl; $i++) {
            $lueckenlos = true;

            for ($j = 1; $j < $benoetigt; $j++) {
                if ($freie[$i + $j]->getTimestamp() - $freie[$i + $j - 1]->getTimestamp() !== $schritt * 60) {
                    $lueckenlos = false;

                    break;
                }
            }

            if ($lueckenlos) {
                $beginne[] = $freie[$i];
            }
        }

        return $beginne;
    }
}
