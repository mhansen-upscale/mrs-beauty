<?php

declare(strict_types=1);

use App\Enums\AbsenceReason;
use App\Enums\ClosureReason;
use App\Enums\Weekday;
use App\Models\Location;
use App\Models\Practitioner;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| WP-08, Abnahmekriterien 4 bis 7 und 13 bis 15
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

/** Ein Behandler, der an diesem Standort montags bis freitags 9 bis 17 Uhr arbeitet. */
function behandlerMitArbeitszeit(Location $standort, string $beginn = '09:00:00', string $ende = '17:00:00'): Practitioner
{
    $behandler = Practitioner::factory()->create();
    $behandler->locations()->attach($standort);

    foreach ([Weekday::Montag, Weekday::Dienstag, Weekday::Mittwoch, Weekday::Donnerstag, Weekday::Freitag] as $tag) {
        $behandler->workingHours()->create([
            'location_id' => $standort->getKey(),
            'weekday' => $tag,
            'starts_at' => $beginn,
            'ends_at' => $ende,
        ]);
    }

    return $behandler;
}

it('wertet die Arbeitszeit in der Zone des Standorts aus', function (): void {
    $berlin = Location::factory()->inZone('Europe/Berlin')->create();
    $behandler = behandlerMitArbeitszeit($berlin);

    // Ein Mittwoch im Januar. 08:00 UTC ist 09:00 in Berlin -- Arbeitsbeginn.
    expect($behandler->arbeitetAm(CarbonImmutable::parse('2027-01-13 08:00:00', 'UTC'), $berlin))->toBeTrue()
        // 07:59 UTC ist 08:59 in Berlin -- noch nicht.
        ->and($behandler->arbeitetAm(CarbonImmutable::parse('2027-01-13 07:59:00', 'UTC'), $berlin))->toBeFalse()
        // 16:00 UTC ist 17:00 in Berlin -- das Ende ist ausschliessend.
        ->and($behandler->arbeitetAm(CarbonImmutable::parse('2027-01-13 16:00:00', 'UTC'), $berlin))->toBeFalse()
        ->and($behandler->arbeitetAm(CarbonImmutable::parse('2027-01-13 15:59:00', 'UTC'), $berlin))->toBeTrue();
});

it('wertet zwei Standorte in verschiedenen Zonen getrennt aus', function (): void {
    $berlin = Location::factory()->inZone('Europe/Berlin')->create();
    $lissabon = Location::factory()->inZone('Europe/Lisbon')->create();

    $inBerlin = behandlerMitArbeitszeit($berlin);
    $inLissabon = behandlerMitArbeitszeit($lissabon);

    // 08:00 UTC: Berlin 09:00 (Arbeitsbeginn), Lissabon 08:00 (noch nicht).
    $zeitpunkt = CarbonImmutable::parse('2027-01-13 08:00:00', 'UTC');

    expect($inBerlin->arbeitetAm($zeitpunkt, $berlin))->toBeTrue()
        ->and($inLissabon->arbeitetAm($zeitpunkt, $lissabon))->toBeFalse();
});

it('behandelt die Sommerzeitluecke korrekt', function (): void {
    // In Berlin springt die Uhr am 28.03.2027 von 02:00 auf 03:00. Die Stunde
    // dazwischen gibt es nicht.
    $berlin = Location::factory()->inZone('Europe/Berlin')->create();
    $behandler = behandlerMitArbeitszeit($berlin, '02:00:00', '04:00:00');

    // 00:30 UTC ist an diesem Tag 01:30 MEZ -- vor dem Sprung, ausserhalb.
    expect($berlin->ortszeit(CarbonImmutable::parse('2027-03-28 00:30:00', 'UTC'))->format('H:i'))->toBe('01:30');

    // 01:00 UTC ist 03:00 MESZ -- direkt nach dem Sprung, im Fenster.
    $nachDemSprung = CarbonImmutable::parse('2027-03-28 01:00:00', 'UTC');
    expect($berlin->ortszeit($nachDemSprung)->format('H:i'))->toBe('03:00');

    // Der 28.03.2027 ist ein Sonntag -- an dem arbeitet niemand. Der Test
    // prueft die Umrechnung, nicht die Buchbarkeit.
    expect(Weekday::fromDate($berlin->ortszeit($nachDemSprung)))->toBe(Weekday::Sonntag);
});

it('behandelt die doppelte Winterzeitstunde korrekt', function (): void {
    // In Berlin gibt es am 25.10.2026 die Stunde 02:00 bis 03:00 zweimal.
    $berlin = Location::factory()->inZone('Europe/Berlin')->create();

    $ersterDurchlauf = CarbonImmutable::parse('2026-10-25 00:30:00', 'UTC');
    $zweiterDurchlauf = CarbonImmutable::parse('2026-10-25 01:30:00', 'UTC');

    // Zwei verschiedene UTC-Zeitpunkte, dieselbe Ortszeit. Beide sind gueltig.
    expect($berlin->ortszeit($ersterDurchlauf)->format('H:i'))->toBe('02:30')
        ->and($berlin->ortszeit($zweiterDurchlauf)->format('H:i'))->toBe('02:30')
        ->and($ersterDurchlauf->equalTo($zweiterDurchlauf))->toBeFalse();
});

it('beruecksichtigt Arbeitszeit, Abwesenheit und Schliesszeit gemeinsam', function (): void {
    $berlin = Location::factory()->inZone('Europe/Berlin')->create();
    $behandler = behandlerMitArbeitszeit($berlin);

    $mittwoch = CarbonImmutable::parse('2027-01-13 10:00:00', 'UTC');

    expect($behandler->arbeitetAm($mittwoch, $berlin))->toBeTrue();

    // V2 -- Abwesenheit
    $abwesenheit = $behandler->absences()->create([
        'reason' => AbsenceReason::Vacation,
        'starts_at' => CarbonImmutable::parse('2027-01-11 00:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2027-01-18 00:00:00', 'UTC'),
    ]);

    expect($behandler->arbeitetAm($mittwoch, $berlin))->toBeFalse();

    $abwesenheit->delete();
    expect($behandler->refresh()->arbeitetAm($mittwoch, $berlin))->toBeTrue();

    // V3 -- Schliesszeit
    $berlin->closures()->create([
        'reason' => ClosureReason::Renovation,
        'starts_at' => CarbonImmutable::parse('2027-01-13 00:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2027-01-14 00:00:00', 'UTC'),
    ]);

    expect($behandler->refresh()->arbeitetAm($mittwoch, $berlin->refresh()))->toBeFalse();
});

it('laesst einen inaktiven Behandler oder Standort nicht arbeiten', function (): void {
    $berlin = Location::factory()->inZone('Europe/Berlin')->create();
    $behandler = behandlerMitArbeitszeit($berlin);
    $mittwoch = CarbonImmutable::parse('2027-01-13 10:00:00', 'UTC');

    $behandler->is_active = false;
    expect($behandler->arbeitetAm($mittwoch, $berlin))->toBeFalse();

    $behandler->is_active = true;
    $berlin->is_active = false;
    expect($behandler->arbeitetAm($mittwoch, $berlin))->toBeFalse();
});

it('zaehlt Wochentage nach ISO 8601', function (): void {
    // Die Wochentagsmaske der Warteliste (K6) muss sich darauf beziehen
    // koennen.
    expect(Weekday::Montag->value)->toBe(1)
        ->and(Weekday::Sonntag->value)->toBe(7)
        ->and(Weekday::fromDate(CarbonImmutable::parse('2027-01-13')))->toBe(Weekday::Mittwoch)
        ->and(Weekday::Montag->bit())->toBe(1)
        ->and(Weekday::Dienstag->bit())->toBe(2)
        ->and(Weekday::Sonntag->bit())->toBe(64);
});
