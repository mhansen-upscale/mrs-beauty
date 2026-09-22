<?php

declare(strict_types=1);

namespace App\Buchung;

use App\Models\AppointmentType;
use App\Models\Location;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;

/**
 * Die Sicht der oeffentlichen Buchungsseite auf die Verfuegbarkeit.
 *
 * Die Engine liefert Slots, sie entscheidet nicht ueber Sichtbarkeit
 * (docs/fachlogik/verfuegbarkeit.md, "Schnittstelle nach aussen"). Hier steht
 * die eine Einschraenkung, die diese Seite gegenueber der internen
 * Terminverwaltung hat: nur oeffentlich buchbare Terminarten, und nur
 * Startzeiten auf dem Anzeigeraster.
 */
final class OeffentlicheVerfuegbarkeit
{
    public function __construct(private readonly Verfuegbarkeit $verfuegbarkeit) {}

    /**
     * Freie Startzeiten, nach Tagen in der Ortszeit des Standorts gruppiert.
     *
     * @return list<array{date: string, weekday: string, slots: list<array<string, mixed>>}>
     */
    public function tage(
        AppointmentType $art,
        Location $standort,
        CarbonImmutable $von,
        ?int $tage = null,
        ?CarbonImmutable $jetzt = null,
    ): array {
        if (! $art->is_public || ! $art->is_active) {
            return [];
        }

        $jetzt ??= CarbonImmutable::now();
        $tage ??= (int) config('mrs.booking.public_days_shown', 28);

        $vorschlaege = $this->verfuegbarkeit->freieStartzeiten(
            art: $art,
            von: $von->max($jetzt),
            bis: $von->addDays($tage),
            nurStandort: $standort,
            jetzt: $jetzt,
        );

        $raster = (int) config('mrs.booking.display_step_minutes', 15);

        /** @var array<string, array<string, array<string, mixed>>> $nachTag */
        $nachTag = [];

        foreach ($vorschlaege as $vorschlag) {
            if (! $this->liegtAufRaster($vorschlag, $standort, $raster)) {
                continue;
            }

            $ortszeit = $vorschlag->ortszeit();
            $tag = $ortszeit->toDateString();
            $uhrzeit = $ortszeit->format('H:i');

            // **Eine Uhrzeit, eine Schaltflaeche.** Arbeiten zwei Behandler
            // gleichzeitig, liefert die Engine denselben Zeitpunkt zweimal --
            // fuer die Interessentin sind das nicht zwei Angebote, sondern
            // dieselbe Uhrzeit doppelt. Wer zuerst kommt, bekommt den Termin;
            // welcher Behandler es wird, steht in der Reservierung.
            $nachTag[$tag] ??= [];
            $nachTag[$tag][$uhrzeit] ??= [
                'time' => $uhrzeit,
                'blocked_from' => $vorschlag->blockedFrom->toIso8601String(),
                'practitioner' => $vorschlag->behandler->uuid,
                'practitioner_name' => $vorschlag->behandler->name(),
            ];
        }

        ksort($nachTag);

        $ergebnis = [];

        foreach ($nachTag as $tag => $slots) {
            ksort($slots);

            $ergebnis[] = [
                'date' => $tag,
                'weekday' => CarbonImmutable::parse($tag)->translatedFormat('l'),
                'slots' => array_values($slots),
            ];
        }

        return $ergebnis;
    }

    /**
     * Liegt die **angezeigte** Startzeit auf dem Anzeigeraster?
     *
     * Gemessen wird die angezeigte Zeit, nicht die belegte. Wer auf
     * `blocked_from` rastert, bietet bei einer Ruestzeit von fuenf Minuten
     * lauter krumme Uhrzeiten an: 09:05, 09:20, 09:35.
     */
    private function liegtAufRaster(Slotvorschlag $vorschlag, Location $standort, int $raster): bool
    {
        $ortszeit = $standort->ortszeit($vorschlag->startsAt);

        return $ortszeit->second === 0 && $ortszeit->minute % $raster === 0;
    }
}
