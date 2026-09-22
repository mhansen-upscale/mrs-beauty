<?php

declare(strict_types=1);

use App\Enums\AbsenceReason;
use App\Enums\ClosureReason;
use App\Enums\HoldPurpose;
use App\Models\AppointmentSlot;
use App\Models\Location;
use App\Termine\NichtBuchbar;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\SlotNichtVerfuegbar;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-11, Abnahmekriterien 8 bis 14 -- Uebersteuern
|--------------------------------------------------------------------------
|
| Uebersteuern hebt die Angebotsgruppe auf (V1, V2, V3, V7, V8, V9, V10) und
| die Belegungsgruppe nicht (V4, V5, V6, V11). Die letzten drei Testfaelle
| dieses Blocks sind der eigentliche Punkt.
|
*/

beforeEach(function (): void {
    alsMandant();
});

/** 18:00 Ortszeit, also eine Stunde nach Feierabend. */
function nachFeierabend(Szenario $szenario): Slotvorschlag
{
    return $szenario->vorschlagAb(Szenario::TAG.' 17:00:00');
}

it('bietet ausserhalb der Arbeitszeit nichts an', function (): void {
    $szenario = new Szenario;

    expect(fn () => app(Terminplaner::class)->buche(
        nachFeierabend($szenario),
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    ))->toThrow(NichtBuchbar::class);
});

it('bucht mit Uebersteuern ausserhalb der Arbeitszeit und legt die fehlenden Zeilen an', function (): void {
    $szenario = new Szenario;
    $vorschlag = nachFeierabend($szenario);

    // Vorher gibt es dort gar keine Slot-Zeilen: WP-10 materialisiert nur,
    // wo jemand arbeitet.
    expect(AppointmentSlot::query()
        ->where('starts_at', '>=', $vorschlag->blockedFrom)
        ->where('starts_at', '<', $vorschlag->blockedUntil)
        ->count())->toBe(0);

    $termin = app(Terminplaner::class)->buche(
        $vorschlag,
        $szenario->kontakt,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    );

    expect($termin->slots()->count())->toBe(6)
        ->and($termin->is_override)->toBeTrue();
});

it('uebersteuert eine Abwesenheit', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    $szenario->aufbau->behandler->absences()->create([
        'reason' => AbsenceReason::Sick,
        'starts_at' => $vorschlag->blockedFrom->subHour(),
        'ends_at' => $vorschlag->blockedUntil->addHour(),
    ]);

    $planer = app(Terminplaner::class);

    expect(fn () => $planer->buche($vorschlag, $szenario->kontakt, jetzt: $szenario->jetzt()))
        ->toThrow(NichtBuchbar::class);

    $termin = $planer->buche(
        $vorschlag,
        $szenario->kontakt,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    );

    expect($termin->is_override)->toBeTrue();
});

it('uebersteuert eine Schliesszeit des Standorts', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    $szenario->aufbau->standort->closures()->create([
        'reason' => ClosureReason::Holiday,
        'starts_at' => $vorschlag->blockedFrom->subHour(),
        'ends_at' => $vorschlag->blockedUntil->addHour(),
    ]);

    $planer = app(Terminplaner::class);

    expect(fn () => $planer->buche($vorschlag, $szenario->kontakt, jetzt: $szenario->jetzt()))
        ->toThrow(NichtBuchbar::class);

    expect($planer->buche(
        $vorschlag,
        $szenario->kontakt,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    )->is_override)->toBeTrue();
});

it('uebersteuert die Vorlaufzeit', function (): void {
    $szenario = new Szenario(vorlaufStunden: 48);
    $vorschlag = $szenario->vorschlag(jetzt: CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'));

    // "Jetzt" ist der Vortag -- 48 Stunden Vorlauf sind damit verletzt.
    $jetzt = $szenario->jetzt();
    $planer = app(Terminplaner::class);

    expect(fn () => $planer->buche($vorschlag, $szenario->kontakt, jetzt: $jetzt))
        ->toThrow(NichtBuchbar::class);

    expect($planer->buche($vorschlag, $szenario->kontakt, uebersteuern: true, jetzt: $jetzt)->is_override)
        ->toBeTrue();
});

it('uebersteuert den Buchungshorizont', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    // Der Horizont liegt bei 90 Tagen; "jetzt" ist ein Jahr frueher.
    $jetzt = CarbonImmutable::parse('2026-01-12 00:00:00', 'UTC');
    $planer = app(Terminplaner::class);

    expect(fn () => $planer->buche($vorschlag, $szenario->kontakt, jetzt: $jetzt))
        ->toThrow(NichtBuchbar::class);

    expect($planer->buche($vorschlag, $szenario->kontakt, uebersteuern: true, jetzt: $jetzt)->is_override)
        ->toBeTrue();
});

it('uebersteuert keine Belegung durch einen Termin', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();
    $planer = app(Terminplaner::class);

    $planer->buche($vorschlag, $szenario->kontakt, jetzt: $szenario->jetzt());

    // **Der Kern des Pakets.** Uebersteuern heisst "wird nicht angeboten,
    // mache ich trotzdem" -- nicht "buche ueber jemanden drueber".
    expect(fn () => $planer->buche(
        $vorschlag,
        $szenario->kontakt,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    ))->toThrow(SlotNichtVerfuegbar::class);
});

it('uebersteuert keinen gueltigen Hold', function (): void {
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    app(SlotHalter::class)->halte($vorschlag, HoldPurpose::WaitlistOffer);

    expect(fn () => app(Terminplaner::class)->buche(
        $vorschlag,
        $szenario->kontakt,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    ))->toThrow(SlotNichtVerfuegbar::class);
});

it('bucht auch mit Uebersteuern keinen Behandler an einen fremden Standort', function (): void {
    $szenario = new Szenario;

    $anderer = Location::factory()->create();

    $vorschlag = new Slotvorschlag(
        art: $szenario->aufbau->art,
        behandler: $szenario->aufbau->behandler,
        standort: $anderer,
        blockedFrom: $szenario->vorschlag()->blockedFrom,
        blockedUntil: $szenario->vorschlag()->blockedUntil,
        startsAt: $szenario->vorschlag()->startsAt,
        endsAt: $szenario->vorschlag()->endsAt,
    );

    // Das ist keine Frage des Angebots, sondern der Stammdaten.
    expect(fn () => app(Terminplaner::class)->buche(
        $vorschlag,
        $szenario->kontakt,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    ))->toThrow(NichtBuchbar::class);
});

it('nimmt keine Zeit ausserhalb des Rasters an', function (): void {
    $szenario = new Szenario;

    expect(fn () => app(Terminplaner::class)->buche(
        $szenario->vorschlagAb(Szenario::TAG.' 17:02:00'),
        $szenario->kontakt,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    ))->toThrow(InvalidArgumentException::class);
});
