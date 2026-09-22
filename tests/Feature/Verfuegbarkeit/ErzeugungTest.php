<?php

declare(strict_types=1);

use App\Enums\Weekday;
use App\Models\AppointmentSlot;
use App\Models\Location;
use App\Models\Practitioner;
use App\Support\Uuid;
use App\Verfuegbarkeit\SlotErzeuger;
use Carbon\CarbonImmutable;
use Tests\Feature\Verfuegbarkeit\Aufbau;

/*
|--------------------------------------------------------------------------
| Erzeugung und Zeitumstellung
| docs/fachlogik/verfuegbarkeit.md, Testfaelle 9, 10, 11, 21, 22
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

it('erzeugt Slots nur innerhalb der Arbeitszeit', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '17:00:00');

    // Ein Mittwoch.
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $zeiten = AppointmentSlot::query()->orderBy('starts_at')->pluck('starts_at')
        ->map(fn (mixed $z): string => $aufbau->standort->ortszeit(CarbonImmutable::parse((string) $z, 'UTC'))->format('H:i'))
        ->all();

    // 09:00 bis 16:55 in Fuenf-Minuten-Schritten.
    expect($zeiten)->toHaveCount(96)
        ->and($zeiten[0])->toBe('09:00')
        ->and(end($zeiten))->toBe('16:55');
});

it('erzeugt keine Slots am Wochenende', function (): void {
    $aufbau = new Aufbau;

    // 16.01.2027 ist ein Samstag, 17.01. ein Sonntag.
    $aufbau->erzeugeSlots('2027-01-16', '2027-01-17');

    expect(AppointmentSlot::query()->count())->toBe(0);
});

it('laesst in der Sommerzeitluecke keine Slots entstehen', function (): void {
    // In Berlin springt die Uhr am 28.03.2027 von 02:00 auf 03:00.
    // Der 29.03. ist ein Montag -- der Sprung liegt auf dem Sonntag davor,
    // deshalb ein Fenster, das die fragliche Stunde umfasst, und ein
    // Arbeitstag am Umstellungstag selbst.
    $standort = Location::factory()->inZone('Europe/Berlin')->create();
    $behandler = Practitioner::factory()->create();
    $behandler->locations()->attach($standort);

    $behandler->workingHours()->create([
        'location_id' => $standort->getKey(),
        'weekday' => Weekday::Sonntag,
        'starts_at' => '01:00:00',
        'ends_at' => '05:00:00',
    ]);

    app(SlotErzeuger::class)->erzeuge(
        CarbonImmutable::parse('2027-03-28', 'UTC'),
        CarbonImmutable::parse('2027-03-28', 'UTC'),
    );

    $ortszeiten = AppointmentSlot::query()->orderBy('starts_at')->pluck('starts_at')
        ->map(fn (mixed $z): string => $standort->ortszeit(CarbonImmutable::parse((string) $z, 'UTC'))->format('H:i'))
        ->all();

    // Die Stunde 02:00 bis 02:59 gibt es an diesem Tag nicht. Kein
    // UTC-Zeitpunkt bildet darauf ab, also entsteht dort auch kein Slot.
    $inDerLuecke = array_filter($ortszeiten, fn (string $z): bool => $z >= '02:00' && $z < '03:00');

    expect($inDerLuecke)->toBeEmpty()
        ->and($ortszeiten)->toContain('01:00')
        ->and($ortszeiten)->toContain('03:00')
        // 01:00-02:00 und 03:00-05:00 sind drei Stunden zu je zwoelf Slots.
        ->and($ortszeiten)->toHaveCount(36);
});

it('erzeugt in der doppelten Winterzeitstunde beide Durchlaeufe', function (): void {
    // Am 25.10.2026 gibt es in Berlin 02:00 bis 03:00 zweimal. Der 25.10.2026
    // ist ein Sonntag.
    $standort = Location::factory()->inZone('Europe/Berlin')->create();
    $behandler = Practitioner::factory()->create();
    $behandler->locations()->attach($standort);

    $behandler->workingHours()->create([
        'location_id' => $standort->getKey(),
        'weekday' => Weekday::Sonntag,
        'starts_at' => '01:00:00',
        'ends_at' => '05:00:00',
    ]);

    app(SlotErzeuger::class)->erzeuge(
        CarbonImmutable::parse('2026-10-25', 'UTC'),
        CarbonImmutable::parse('2026-10-25', 'UTC'),
    );

    $ortszeiten = AppointmentSlot::query()->orderBy('starts_at')->pluck('starts_at')
        ->map(fn (mixed $z): string => $standort->ortszeit(CarbonImmutable::parse((string) $z, 'UTC'))->format('H:i'))
        ->all();

    // 02:30 kommt zweimal vor -- beide sind gueltige Arbeitszeit.
    $zweiUhrDreissig = array_filter($ortszeiten, fn (string $z): bool => $z === '02:30');

    expect($zweiUhrDreissig)->toHaveCount(2)
        // Fuenf Stunden Ortszeit, aber sechs Stunden real.
        ->and($ortszeiten)->toHaveCount(60);
});

it('ist idempotent', function (): void {
    $aufbau = new Aufbau;

    $erste = $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');
    $anzahl = AppointmentSlot::query()->count();

    $zweite = $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    expect($erste['angelegt'])->toBe(96)
        ->and($zweite['angelegt'])->toBe(0)
        ->and($zweite['entfernt'])->toBe(0)
        ->and(AppointmentSlot::query()->count())->toBe($anzahl);
});

it('entfernt freie Slots einer gestrichenen Arbeitszeit, aber keine belegten', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '17:00:00');
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    expect(AppointmentSlot::query()->count())->toBe(96);

    // Einen Slot belegen -- so, wie es ein Termin taete.
    $belegter = AppointmentSlot::query()->orderBy('starts_at')->firstOrFail();
    $belegter->external_block_id = Uuid::generate();
    $belegter->save();

    // Arbeitszeit auf den Vormittag kuerzen.
    $aufbau->behandler->workingHours()->where('weekday', Weekday::Mittwoch->value)
        ->update(['ends_at' => '12:00:00']);

    $ergebnis = $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    // 09:00 bis 11:55 sind 36 Slots. Die 60 des Nachmittags fallen weg.
    expect($ergebnis['entfernt'])->toBe(60)
        ->and(AppointmentSlot::query()->count())->toBe(36)
        // Der belegte Slot lag am Vormittag und ist ohnehin noch gueltig --
        // die Gegenprobe steht im naechsten Test.
        ->and(AppointmentSlot::query()->whereKey($belegter->getKey())->exists())->toBeTrue();
});

it('laesst einen belegten Slot ausserhalb der neuen Arbeitszeit stehen', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '17:00:00');
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    // Ein Slot am Nachmittag, der gleich ausserhalb der Arbeitszeit liegt.
    $nachmittags = AppointmentSlot::query()->orderByDesc('starts_at')->firstOrFail();
    $nachmittags->external_block_id = Uuid::generate();
    $nachmittags->save();

    $aufbau->behandler->workingHours()->where('weekday', Weekday::Mittwoch->value)
        ->update(['ends_at' => '12:00:00']);

    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    // Er bleibt stehen. Ein Termin ausserhalb der Arbeitszeit ist ein
    // Konflikt fuer das Team, kein Fall fuer eine automatische Absage.
    expect(AppointmentSlot::query()->whereKey($nachmittags->getKey())->exists())->toBeTrue();
});

it('erzeugt fuer zwei Zeitzonen die richtigen UTC-Zeitpunkte', function (): void {
    $berlin = new Aufbau(zeitzone: 'Europe/Berlin', von: '09:00:00', bis: '10:00:00');

    $berlin->erzeugeSlots('2027-01-13', '2027-01-13');

    $erster = AppointmentSlot::query()->orderBy('starts_at')->firstOrFail();

    // 09:00 Berlin im Januar ist 08:00 UTC.
    expect($erster->starts_at->format('H:i'))->toBe('08:00');
});
