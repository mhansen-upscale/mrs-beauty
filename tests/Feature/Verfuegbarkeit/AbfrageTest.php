<?php

declare(strict_types=1);

use App\Enums\AbsenceReason;
use App\Enums\ClosureReason;
use App\Enums\HoldPurpose;
use App\Models\AppointmentSlot;
use App\Models\Practitioner;
use App\Support\Uuid;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;
use Tests\Feature\Verfuegbarkeit\Aufbau;

/*
|--------------------------------------------------------------------------
| docs/fachlogik/verfuegbarkeit.md, Testfaelle 1 bis 8, 12, 18
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

/** Der Mittwoch, an dem die meisten Testfaelle spielen. */
function mittwoch(string $uhrzeit = '00:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse("2027-01-13 {$uhrzeit}", 'UTC');
}

/**
 * @return list<string>
 */
function startzeiten(Aufbau $aufbau, ?CarbonImmutable $jetzt = null): array
{
    return array_map(
        fn (Slotvorschlag $v): string => $aufbau->standort->ortszeit($v->startsAt)->format('H:i'),
        app(Verfuegbarkeit::class)->freieStartzeiten(
            art: $aufbau->art,
            von: mittwoch(),
            bis: mittwoch()->addDay(),
            jetzt: $jetzt ?? mittwoch()->subDay(),
        )
    );
}

it('schlaegt nur Zeiten innerhalb der Arbeitszeit vor', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '11:00:00', dauer: 30);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $zeiten = startzeiten($aufbau);

    // 09:00 bis 10:30 in Fuenf-Minuten-Schritten -- der letzte Start, der noch
    // dreissig Minuten Platz hat.
    expect($zeiten[0])->toBe('09:00')
        ->and(end($zeiten))->toBe('10:30');
});

it('schlaegt nichts in einer Abwesenheit vor', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '11:00:00', dauer: 30);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    // Achtung, die uebliche Falle: die Arbeitszeit steht in **Ortszeit**
    // (09:00 bis 11:00 Berlin), die Abwesenheit in **UTC**. Berlin liegt im
    // Januar eine Stunde vor UTC, das Arbeitsfenster ist also 08:00 bis 10:00
    // UTC. Eine Abwesenheit von 09:00 bis 12:00 UTC wuerde nur die zweite
    // Haelfte treffen.
    $aufbau->behandler->absences()->create([
        'reason' => AbsenceReason::Sick,
        'starts_at' => mittwoch('07:00:00'),
        'ends_at' => mittwoch('12:00:00'),
    ]);

    expect(startzeiten($aufbau))->toBeEmpty();
});

it('sperrt auch eine Abwesenheit, die nur einen Teil der Strecke trifft', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '11:00:00', dauer: 30);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    // Nur die zweite Stunde des Fensters (09:00 bis 10:00 UTC = 10:00 bis
    // 11:00 Berlin).
    $aufbau->behandler->absences()->create([
        'reason' => AbsenceReason::Training,
        'starts_at' => mittwoch('09:00:00'),
        'ends_at' => mittwoch('10:00:00'),
    ]);

    $zeiten = startzeiten($aufbau);

    // Die erste Stunde bleibt, aber nur bis der Termin in die Abwesenheit
    // hineinragen wuerde: der letzte Start ist 09:30 Ortszeit.
    expect($zeiten)->not->toBeEmpty()
        ->and($zeiten[0])->toBe('09:00')
        ->and(end($zeiten))->toBe('09:30');
});

it('schlaegt nichts in einer Schliesszeit vor', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '11:00:00', dauer: 30);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $aufbau->standort->closures()->create([
        'reason' => ClosureReason::Holiday,
        'starts_at' => mittwoch('07:00:00'),
        'ends_at' => mittwoch('12:00:00'),
    ]);

    expect(startzeiten($aufbau))->toBeEmpty();
});

it('schlaegt nichts vor, wenn die volle Dauer nicht am Stueck frei ist', function (): void {
    // Arbeitszeit 09:00 bis 09:40 Ortszeit -- das sind 40 Minuten.
    $aufbau = new Aufbau(von: '09:00:00', bis: '09:40:00', dauer: 45);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    expect(AppointmentSlot::query()->count())->toBe(8)
        // 45 Minuten passen nicht in 40.
        ->and(startzeiten($aufbau))->toBeEmpty();
});

it('belegt die Ruestzeit, zeigt sie aber nicht als Terminzeit', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 30, ruestzeitDavor: 10, ruestzeitDanach: 5);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlaege = app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $aufbau->art,
        von: mittwoch(),
        bis: mittwoch()->addDay(),
        jetzt: mittwoch()->subDay(),
    );

    $erster = $vorschlaege[0];

    // Der Block beginnt um 09:00 Ortszeit, der Termin um 09:10.
    expect($aufbau->standort->ortszeit($erster->blockedFrom)->format('H:i'))->toBe('09:00')
        ->and($aufbau->standort->ortszeit($erster->startsAt)->format('H:i'))->toBe('09:10')
        ->and($aufbau->standort->ortszeit($erster->endsAt)->format('H:i'))->toBe('09:40')
        // Belegt sind 45 Minuten, angezeigt 30.
        ->and($erster->blockedFrom->diffInMinutes($erster->blockedUntil))->toBe(45.0)
        ->and($erster->startsAt->diffInMinutes($erster->endsAt))->toBe(30.0);
});

it('schlaegt nichts vor, wenn der Behandler nicht freigegeben ist', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '11:00:00', dauer: 30);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $aufbau->art->practitioners()->detach();

    expect(startzeiten($aufbau))->toBeEmpty();
});

it('schlaegt nichts vor, was innerhalb der Vorlaufzeit liegt', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '17:00:00', dauer: 30, vorlaufStunden: 48);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    // Jetzt ist der Vortag um 12:00 -- alles am Mittwoch liegt innerhalb der
    // 48 Stunden.
    expect(startzeiten($aufbau, mittwoch()->subDay()->addHours(12)))->toBeEmpty();

    // Drei Tage vorher ist alles buchbar.
    expect(startzeiten($aufbau, mittwoch()->subDays(3)))->not->toBeEmpty();
});

it('schlaegt nichts jenseits des Buchungshorizonts vor', function (): void {
    config()->set('mrs.booking.horizon_days', 1);

    $aufbau = new Aufbau(von: '09:00:00', bis: '11:00:00', dauer: 30);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    // Jetzt ist zehn Tage vorher -- der Horizont endet nach einem Tag.
    expect(startzeiten($aufbau, mittwoch()->subDays(10)))->toBeEmpty();
});

it('macht einen Slot mit gueltigem Hold unsichtbar', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $jetzt = mittwoch()->subDay();

    $vorher = startzeiten($aufbau, $jetzt);
    expect($vorher)->toHaveCount(1);

    $vorschlag = app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $aufbau->art, von: mittwoch(), bis: mittwoch()->addDay(), jetzt: $jetzt
    )[0];

    app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking, $jetzt);

    expect(startzeiten($aufbau, $jetzt))->toBeEmpty();
});

it('macht einen Slot mit externem Blocker unbuchbar', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    expect(startzeiten($aufbau))->toHaveCount(1);

    // Ein Blocker aus dem Kalendersync (WP-14) mitten in der Strecke.
    AppointmentSlot::query()->orderBy('starts_at')->skip(3)->take(1)->get()
        ->each(function (AppointmentSlot $slot): void {
            $slot->external_block_id = Uuid::generate();
            $slot->save();
        });

    expect(startzeiten($aufbau))->toBeEmpty();
});

it('schlaegt nichts fuer einen zweiten Behandler ohne Arbeitszeit vor', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '11:00:00', dauer: 30);

    $zweiter = Practitioner::factory()->create();
    $zweiter->locations()->attach($aufbau->standort);
    $aufbau->art->practitioners()->attach($zweiter);

    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlaege = app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $aufbau->art, von: mittwoch(), bis: mittwoch()->addDay(), jetzt: mittwoch()->subDay()
    );

    // Alle Vorschlaege stammen vom ersten Behandler -- der zweite hat keine
    // Arbeitszeit, also auch keine Slots.
    $behandler = array_unique(array_map(fn (Slotvorschlag $v): string => (string) $v->behandler->uuid, $vorschlaege));

    expect($behandler)->toHaveCount(1)
        ->and($behandler[0] ?? null)->toBe($aufbau->behandler->uuid);
});
