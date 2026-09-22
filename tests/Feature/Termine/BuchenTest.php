<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\HoldPurpose;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Termine\NichtBuchbar;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\SlotNichtVerfuegbar;
use Carbon\CarbonImmutable;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-11, Abnahmekriterien 1 bis 7 -- Buchen
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

it('belegt genau die Zeilen der belegten Strecke', function (): void {
    $szenario = new Szenario(dauer: 30, ruestzeitDavor: 10, ruestzeitDanach: 5);
    $vorschlag = $szenario->vorschlag();

    $termin = app(Terminplaner::class)->buche(
        $vorschlag,
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    // 45 Minuten belegte Strecke sind neun Zeilen.
    expect($termin->slots()->count())->toBe(9)
        ->and($termin->contact_id)->toBe($szenario->kontakt->getKey());
});

it('zeigt die Terminzeit ohne Ruestzeit und belegt sie mit', function (): void {
    $szenario = new Szenario(dauer: 30, ruestzeitDavor: 10, ruestzeitDanach: 5);
    $vorschlag = $szenario->vorschlag();

    $termin = app(Terminplaner::class)->buche(
        $vorschlag,
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    // Der Kontakt liest 09:10 bis 09:40, im Kalender steht 09:00 bis 09:45.
    expect($termin->starts_at->diffInMinutes($termin->ends_at))->toBe(30.0)
        ->and($termin->blocked_from->diffInMinutes($termin->blocked_until))->toBe(45.0)
        ->and($termin->blocked_from->lessThan($termin->starts_at))->toBeTrue()
        ->and($termin->blocked_until->greaterThan($termin->ends_at))->toBeTrue();
});

it('laesst keinen zweiten Termin auf derselben Strecke zu', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();
    $planer = app(Terminplaner::class);

    $planer->buche($vorschlag, $szenario->kontakt, jetzt: $szenario->jetzt());

    expect(fn () => $planer->buche($vorschlag, $szenario->kontakt, jetzt: $szenario->jetzt()))
        ->toThrow(SlotNichtVerfuegbar::class);

    expect(Appointment::query()->count())->toBe(1);
});

it('laesst keinen Termin auf einer Strecke mit gueltigem Hold zu', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);

    expect(fn () => app(Terminplaner::class)->buche(
        $vorschlag,
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    ))->toThrow(SlotNichtVerfuegbar::class);
});

it('laesst einen Termin auf einer Strecke mit abgelaufenem Hold zu', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);

    // Abgelaufen ist abgelaufen -- ohne Aufraeumjob.
    $hold->expires_at = CarbonImmutable::now()->subMinute();
    $hold->save();

    $termin = app(Terminplaner::class)->buche(
        $vorschlag,
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    // Und die Zeilen tragen danach genau **eine** Belegung: der Verweis auf
    // den abgelaufenen Hold ist mit weg.
    expect($termin->slots()->count())->toBeGreaterThan(0)
        ->and(AppointmentSlot::query()
            ->whereNotNull('appointment_id')
            ->whereNotNull('slot_hold_id')
            ->count())->toBe(0);
});

it('laesst eine inaktive Terminart nicht buchen', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    $szenario->aufbau->art->is_active = false;
    $szenario->aufbau->art->save();

    expect(fn () => app(Terminplaner::class)->buche(
        $vorschlag,
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    ))->toThrow(NichtBuchbar::class);
});

it('haelt den Buchungskanal und den Status fest', function (): void {
    $szenario = new Szenario;

    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $szenario->kontakt,
        kanal: BookingChannel::Agent,
        status: AppointmentStatus::Pending,
        jetzt: $szenario->jetzt(),
    );

    expect($termin->booked_via)->toBe(BookingChannel::Agent)
        ->and($termin->status)->toBe(AppointmentStatus::Pending)
        ->and($termin->is_override)->toBeFalse();
});

it('bucht vom Empfang aus bestaetigt', function (): void {
    $szenario = new Szenario;

    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    // Wer am Empfang sitzt und einen Termin eintraegt, hat gerade mit der
    // Person gesprochen. Die Buchungsseite bucht 'pending'.
    expect($termin->status)->toBe(AppointmentStatus::Confirmed)
        ->and($termin->booked_via)->toBe(BookingChannel::Internal);
});
