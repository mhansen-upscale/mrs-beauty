<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\HoldPurpose;
use App\Models\AppointmentSlot;
use App\Models\Contact;
use App\Models\SlotHold;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\SlotNichtVerfuegbar;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;

use function Pest\Laravel\travelTo;

use Tests\Feature\Verfuegbarkeit\Aufbau;

/*
|--------------------------------------------------------------------------
| docs/fachlogik/verfuegbarkeit.md, Testfaelle 12 bis 15
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

function ersterVorschlag(Aufbau $aufbau, ?CarbonImmutable $jetzt = null): Slotvorschlag
{
    $jetzt ??= CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');

    $vorschlaege = app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $aufbau->art,
        von: CarbonImmutable::parse('2027-01-13 00:00:00', 'UTC'),
        bis: CarbonImmutable::parse('2027-01-14 00:00:00', 'UTC'),
        jetzt: $jetzt,
    );

    return $vorschlaege[0] ?? throw new RuntimeException('Kein Vorschlag vorhanden.');
}

it('belegt beim Halten genau die Zeilen der belegten Strecke', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '17:00:00', dauer: 30, ruestzeitDavor: 10, ruestzeitDanach: 5);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = ersterVorschlag($aufbau);
    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);

    // 45 Minuten belegte Strecke sind neun Zeilen.
    expect($hold->slots()->count())->toBe(9)
        ->and($hold->giltNoch())->toBeTrue();
});

it('gibt einen abgelaufenen Hold sofort frei, auch ohne Aufraeumjob', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $jetzt = CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');
    $vorschlag = ersterVorschlag($aufbau, $jetzt);

    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking, $jetzt);

    // Waehrend der Hold gilt, ist nichts frei.
    expect(AppointmentSlot::query()->frei()->count())->toBe(0);

    // Die Uhr laeuft weiter -- kein Aufraeumjob.
    travelTo($jetzt->addMinutes(11));

    expect($hold->istAbgelaufen())->toBeTrue()
        ->and(AppointmentSlot::query()->frei()->count())->toBe(12)
        // Die Zeile traegt den Hold noch, gilt aber als frei.
        ->and(AppointmentSlot::query()->whereNotNull('slot_hold_id')->count())->toBe(12);
});

it('laesst keinen zweiten Hold auf dieselben Zeilen zu', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = ersterVorschlag($aufbau);

    app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);
    app(SlotHalter::class)->halte($vorschlag, HoldPurpose::AgentDialog);
})->throws(SlotNichtVerfuegbar::class);

it('laesst nach Ablauf einen neuen Hold zu', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $jetzt = CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');
    $vorschlag = ersterVorschlag($aufbau, $jetzt);

    app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking, $jetzt);

    travelTo($jetzt->addMinutes(11));

    $zweiter = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::AgentDialog, $jetzt->addMinutes(11));

    expect($zweiter->giltNoch())->toBeTrue()
        ->and($zweiter->slots()->count())->toBe(12);
});

it('behaelt bei der Umwandlung dieselben Zeilen', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '17:00:00', dauer: 30, ruestzeitDavor: 10, ruestzeitDanach: 5);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = ersterVorschlag($aufbau);
    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);

    $gehaltene = $hold->slots()->orderBy('starts_at')->pluck('id')->all();

    $termin = app(SlotHalter::class)->wandleUm($hold, $vorschlag, Contact::factory()->create());

    $belegte = AppointmentSlot::query()
        ->where('appointment_id', $termin->getKey())
        ->orderBy('starts_at')
        ->pluck('id')
        ->all();

    // Exakt dieselben Zeilen. Kein Zwischenzustand, in dem sie frei waeren.
    expect($belegte)->toBe($gehaltene)
        ->and($hold->refresh()->released_at)->not->toBeNull()
        ->and(AppointmentSlot::query()->whereNotNull('slot_hold_id')->count())->toBe(0)
        ->and($termin->status)->toBe(AppointmentStatus::Pending);
});

it('traegt beim Termin angezeigte und belegte Zeit getrennt ein', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '17:00:00', dauer: 30, ruestzeitDavor: 10, ruestzeitDanach: 5);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = ersterVorschlag($aufbau);
    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);
    $termin = app(SlotHalter::class)->wandleUm($hold, $vorschlag, Contact::factory()->create());

    expect($termin->starts_at->diffInMinutes($termin->ends_at))->toBe(30.0)
        ->and($termin->blocked_from->diffInMinutes($termin->blocked_until))->toBe(45.0)
        ->and($termin->blocked_from->lessThan($termin->starts_at))->toBeTrue();
});

it('wandelt einen abgelaufenen Hold nicht mehr um', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $jetzt = CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');
    $vorschlag = ersterVorschlag($aufbau, $jetzt);
    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking, $jetzt);

    travelTo($jetzt->addMinutes(11));

    app(SlotHalter::class)->wandleUm($hold, $vorschlag, Contact::factory()->create());
})->throws(SlotNichtVerfuegbar::class, 'inzwischen vergeben');

it('gibt einen Hold auf Zuruf frei', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = ersterVorschlag($aufbau);
    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::WaitlistOffer);

    app(SlotHalter::class)->gibFrei($hold);

    expect(AppointmentSlot::query()->frei()->count())->toBe(12)
        ->and(AppointmentSlot::query()->whereNotNull('slot_hold_id')->count())->toBe(0)
        ->and($hold->refresh()->giltNoch())->toBeFalse();
});

it('raeumt abgelaufene Holds auf, ohne etwas zu entscheiden', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $jetzt = CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');
    $vorschlag = ersterVorschlag($aufbau, $jetzt);
    app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking, $jetzt);

    travelTo($jetzt->addMinutes(11));

    // Die Slots galten schon vorher als frei -- der Job haelt nur die Tabelle
    // sauber.
    expect(app(SlotHalter::class)->raeumeAbgelaufeneAuf())->toBe(1)
        ->and(AppointmentSlot::query()->whereNotNull('slot_hold_id')->count())->toBe(0)
        ->and(SlotHold::query()->gueltig()->count())->toBe(0);
});

it('haelt die Lebensdauer je Zweck', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '17:00:00', dauer: 30);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $jetzt = CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');

    $agent = app(SlotHalter::class)->halte(ersterVorschlag($aufbau, $jetzt), HoldPurpose::AgentDialog, $jetzt);
    app(SlotHalter::class)->gibFrei($agent);

    $warteliste = app(SlotHalter::class)->halte(ersterVorschlag($aufbau, $jetzt), HoldPurpose::WaitlistOffer, $jetzt);

    // Buchungsdialog 15 Minuten (agent.md), Wartelistenangebot 30
    // (warteliste.md).
    expect($jetzt->diffInMinutes($agent->expires_at))->toBe(15.0)
        ->and($jetzt->diffInMinutes($warteliste->expires_at))->toBe(30.0);
});
