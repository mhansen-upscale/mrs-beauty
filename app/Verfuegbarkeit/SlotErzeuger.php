<?php

declare(strict_types=1);

namespace App\Verfuegbarkeit;

use App\Enums\Weekday;
use App\Models\AppointmentSlot;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\WorkingHour;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materialisiert die Verfuegbarkeit in appointment_slots (Entscheidung A9).
 *
 * # Warum UTC abgeschritten wird und nicht Ortszeit
 *
 * Die naheliegende Bauweise waere: nimm "montags 9 Uhr", setze es auf jeden
 * Montag im Zeitraum und rechne in UTC um. Sie laeuft genau in die
 * Zeitumstellung -- am Umstellungssonntag gibt es eine Ortszeit nicht und eine
 * andere zweimal, und die Umrechnung muesste beides als Sonderfall behandeln.
 *
 * Deshalb die Gegenrichtung: der Zeitraum wird in **UTC** in
 * 5-Minuten-Schritten abgeschritten, und bei jedem Schritt wird gefragt,
 * welche Ortszeit das ist und ob sie in einem Arbeitszeitfenster liegt. Diese
 * Richtung ist immer eindeutig.
 *
 * Damit loesen sich beide Faelle von selbst:
 *
 * - **Sommerzeitluecke:** kein UTC-Zeitpunkt bildet auf die fehlende Stunde
 *   ab, also entstehen dort keine Slots.
 * - **Doppelte Winterzeitstunde:** zwei UTC-Zeitpunkte bilden auf dieselbe
 *   Ortszeit ab, beide erzeugen einen Slot. Beide sind gueltige Arbeitszeit.
 *
 * Kein Sonderfall, keine Ausnahmebehandlung.
 */
final class SlotErzeuger
{
    public function __construct(private readonly TenantContext $mandant) {}

    /**
     * Erzeugt und bereinigt die Slots eines Zeitraums.
     *
     * Idempotent: ein zweiter Lauf ueber denselben Zeitraum aendert nichts.
     * Belegte Slots werden **nie** entfernt -- entsteht durch eine geaenderte
     * Arbeitszeit ein Termin ausserhalb der Arbeitszeit, ist das ein Konflikt
     * fuer das Team, kein Fall fuer eine automatische Absage.
     *
     * @return array{angelegt: int, entfernt: int}
     */
    public function erzeuge(?CarbonImmutable $von = null, ?CarbonImmutable $bis = null): array
    {
        $von ??= CarbonImmutable::now()->startOfDay();
        $bis ??= $von->addDays((int) config('mrs.booking.horizon_days', 90));

        $angelegt = 0;
        $entfernt = 0;

        $standorte = Location::query()->where('is_active', true)->get();

        foreach ($standorte as $standort) {
            $behandler = $standort->practitioners()
                ->where('practitioners.is_active', true)
                ->get();

            foreach ($behandler as $person) {
                $fenster = $this->fensterVon($person, $standort);

                if ($fenster->isEmpty()) {
                    continue;
                }

                $gewuenscht = $this->gewuenschteZeitpunkte($standort, $fenster, $von, $bis);

                $angelegt += $this->legeAn($person, $standort, $gewuenscht);
                $entfernt += $this->raeumeAuf($person, $standort, $gewuenscht, $von, $bis);
            }
        }

        return ['angelegt' => $angelegt, 'entfernt' => $entfernt];
    }

    /**
     * Die Arbeitszeitfenster eines Behandlers an einem Standort, nach
     * Wochentag gruppiert. Einmal geladen, danach im Speicher geprueft --
     * eine Abfrage je 5-Minuten-Schritt waere bei 25 000 Schritten je Paar
     * nicht vertretbar.
     *
     * @return Collection<int|string, Collection<int, WorkingHour>>
     */
    private function fensterVon(Practitioner $person, Location $standort): Collection
    {
        /** @var Collection<int|string, Collection<int, WorkingHour>> */
        return $person->workingHours()
            ->where('location_id', $standort->getKey())
            ->get()
            ->groupBy(fn (WorkingHour $fenster): int => $fenster->weekday->value);
    }

    /**
     * Die UTC-Zeitpunkte, an denen dieser Behandler an diesem Standort
     * arbeitet.
     *
     * @param  Collection<int|string, Collection<int, WorkingHour>>  $fenster
     * @return list<string> UTC als 'Y-m-d H:i:s'
     */
    private function gewuenschteZeitpunkte(
        Location $standort,
        Collection $fenster,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): array {
        $zone = $standort->zone();
        $schritt = (int) config('mrs.booking.slot_minutes', 5);

        // Grosszuegig gewaehlte UTC-Grenzen: die Ortszeit entscheidet, was
        // tatsaechlich zaehlt. Ein Tag Puffer auf beiden Seiten deckt jede
        // Zeitzonenverschiebung ab.
        $zeiger = CarbonImmutable::parse($von->toDateString().' 00:00:00', $zone)
            ->subDay()
            ->setTimezone('UTC');

        $ende = CarbonImmutable::parse($bis->toDateString().' 00:00:00', $zone)
            ->addDays(2)
            ->setTimezone('UTC');

        $vonDatum = $von->toDateString();
        $bisDatum = $bis->toDateString();

        $zeitpunkte = [];

        while ($zeiger < $ende) {
            $ortszeit = $zeiger->setTimezone($zone);
            $datum = $ortszeit->toDateString();

            if ($datum >= $vonDatum && $datum <= $bisDatum) {
                $tag = $fenster->get(Weekday::fromDate($ortszeit)->value);

                if ($tag !== null) {
                    $uhrzeit = $ortszeit->format('H:i:s');

                    foreach ($tag as $eintrag) {
                        if ($uhrzeit >= $eintrag->starts_at && $uhrzeit < $eintrag->ends_at) {
                            $zeitpunkte[] = $zeiger->format('Y-m-d H:i:s');

                            break;
                        }
                    }
                }
            }

            $zeiger = $zeiger->addMinutes($schritt);
        }

        return $zeitpunkte;
    }

    /**
     * @param  list<string>  $gewuenscht
     */
    private function legeAn(Practitioner $person, Location $standort, array $gewuenscht): int
    {
        if ($gewuenscht === []) {
            return 0;
        }

        $vorhanden = AppointmentSlot::query()
            ->where('practitioner_id', $person->getKey())
            ->whereIn('starts_at', $gewuenscht)
            ->pluck('starts_at')
            ->map(fn (mixed $zeit): string => CarbonImmutable::parse((string) $zeit)->format('Y-m-d H:i:s'))
            ->all();

        $fehlend = array_values(array_diff($gewuenscht, $vorhanden));

        if ($fehlend === []) {
            return 0;
        }

        $organisation = $this->mandant->requireId();
        $angelegt = 0;

        foreach (array_chunk($fehlend, 1000) as $stapel) {
            $zeilen = array_map(fn (string $zeitpunkt): array => [
                'id' => Uuid::generate(),
                'organization_id' => $organisation,
                'practitioner_id' => $person->getKey(),
                'location_id' => $standort->getKey(),
                'starts_at' => $zeitpunkt,
                'appointment_id' => null,
                'slot_hold_id' => null,
                'external_block_id' => null,
            ], $stapel);

            // insertOrIgnore statt insert: der Unique-Index
            // (practitioner_id, starts_at) faengt ab, was ein paralleler Lauf
            // schon angelegt hat. Das ist die Idempotenz.
            $angelegt += DB::table('appointment_slots')->insertOrIgnore($zeilen);
        }

        return $angelegt;
    }

    /**
     * Entfernt freie Slots, die nicht mehr in eine Arbeitszeit fallen.
     *
     * **Nur freie.** Ein belegter Slot bleibt stehen, auch wenn die
     * Arbeitszeit sich geaendert hat -- das ist ein Konflikt fuer das Team.
     *
     * @param  list<string>  $gewuenscht
     */
    private function raeumeAuf(
        Practitioner $person,
        Location $standort,
        array $gewuenscht,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): int {
        $behalten = array_flip($gewuenscht);

        $ueberfluessig = AppointmentSlot::query()
            ->frei()
            ->where('practitioner_id', $person->getKey())
            ->where('location_id', $standort->getKey())
            ->where('starts_at', '>=', $von->startOfDay()->subDay())
            ->where('starts_at', '<', $bis->addDays(2)->startOfDay())
            ->get(['id', 'starts_at'])
            ->reject(fn (AppointmentSlot $slot): bool => isset($behalten[$slot->starts_at->format('Y-m-d H:i:s')]))
            ->pluck('id');

        if ($ueberfluessig->isEmpty()) {
            return 0;
        }

        return AppointmentSlot::query()->whereIn('id', $ueberfluessig)->delete();
    }
}
