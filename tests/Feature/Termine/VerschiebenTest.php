<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\CancellationReason;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Practitioner;
use App\Termine\TerminNichtAenderbar;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotNichtVerfuegbar;
use App\Verfuegbarkeit\Slotvorschlag;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-11, Abnahmekriterien 15 bis 20 -- Verschieben
|--------------------------------------------------------------------------
|
| Verschieben ist **dieselbe Zeile**. Nicht absagen und neu anlegen:
| Erinnerungen, Kalendersync und Attribution haengen an der Identitaet des
| Termins.
|
*/

beforeEach(function (): void {
    alsMandant();
});

it('behaelt beim Verschieben die Identitaet des Termins', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(0), $szenario->kontakt, jetzt: $szenario->jetzt());
    $vorher = $termin->getKey();

    $planer->verschiebe($termin, $szenario->vorschlag(20), jetzt: $szenario->jetzt());

    expect($termin->getKey())->toBe($vorher)
        ->and(Appointment::query()->count())->toBe(1);
});

it('gibt die alten Zeilen frei und belegt die neuen', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $frueh = $szenario->vorschlag(0);
    $termin = $planer->buche($frueh, $szenario->kontakt, jetzt: $szenario->jetzt());

    $spaet = $szenario->vorschlag(20);
    $planer->verschiebe($termin, $spaet, jetzt: $szenario->jetzt());

    $alte = AppointmentSlot::query()
        ->where('starts_at', '>=', $frueh->blockedFrom)
        ->where('starts_at', '<', $frueh->blockedUntil)
        ->get();

    expect($alte->every(fn (AppointmentSlot $zeile): bool => $zeile->appointment_id === null))->toBeTrue()
        ->and($termin->slots()->count())->toBe(6)
        ->and($termin->fresh()?->starts_at->equalTo($spaet->startsAt))->toBeTrue();
});

it('verschiebt auch auf eine ueberlappende Strecke', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $frueh = $szenario->vorschlag(0);

    // Zehn Minuten spaeter -- die Strecken ueberlappen sich fast vollstaendig.
    // Beide Vorschlaege **vor** der Buchung holen: danach sind die ersten
    // Zeilen belegt und die Liste beginnt woanders.
    $verschoben = $szenario->vorschlag(2);

    expect($verschoben->blockedFrom->lessThan($frueh->blockedUntil))->toBeTrue();

    // Fuer diesen Termin sind seine eigenen Zeilen frei, fuer jeden anderen
    // nicht.
    $termin = $planer->buche($frueh, $szenario->kontakt, jetzt: $szenario->jetzt());

    $planer->verschiebe($termin, $verschoben, jetzt: $szenario->jetzt());

    expect($termin->slots()->count())->toBe(6)
        ->and($termin->fresh()?->starts_at->equalTo($verschoben->startsAt))->toBeTrue();
});

it('laesst den Termin unveraendert, wenn das Ziel belegt ist', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $eigener = $szenario->vorschlag(0);
    $termin = $planer->buche($eigener, $szenario->kontakt, jetzt: $szenario->jetzt());

    $fremder = $szenario->vorschlag(20);
    $planer->buche($fremder, $szenario->kontakt, jetzt: $szenario->jetzt());

    expect(fn () => $planer->verschiebe($termin, $fremder, jetzt: $szenario->jetzt()))
        ->toThrow(SlotNichtVerfuegbar::class);

    // Nichts angefasst: gleiche Zeit, gleiche Zeilen.
    $frisch = $termin->fresh();

    expect($frisch?->starts_at->equalTo($eigener->startsAt))->toBeTrue()
        ->and($frisch?->slots()->count())->toBe(6);
});

it('verschiebt zu einem anderen Behandler', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(0), $szenario->kontakt, jetzt: $szenario->jetzt());

    $zweiter = Practitioner::factory()->create();
    $zweiter->locations()->attach($szenario->aufbau->standort);
    $szenario->aufbau->art->practitioners()->attach($zweiter);

    foreach ($szenario->aufbau->behandler->workingHours as $zeit) {
        $zweiter->workingHours()->create([
            'location_id' => $zeit->location_id,
            'weekday' => $zeit->weekday,
            'starts_at' => $zeit->starts_at,
            'ends_at' => $zeit->ends_at,
        ]);
    }

    $szenario->aufbau->erzeugeSlots(Szenario::TAG, Szenario::TAG);

    $ziel = Slotvorschlag::ab(
        $szenario->aufbau->art,
        $zweiter,
        $szenario->aufbau->standort,
        $szenario->vorschlag(0)->blockedFrom,
    );

    $planer->verschiebe($termin, $ziel, jetzt: $szenario->jetzt());

    expect($termin->practitioner_id)->toBe($zweiter->getKey())
        ->and($termin->slots()->count())->toBe(6)
        ->and(AppointmentSlot::query()
            ->where('practitioner_id', $szenario->aufbau->behandler->getKey())
            ->whereNotNull('appointment_id')
            ->count())->toBe(0);
});

it('verschiebt keinen abgesagten Termin', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(0), $szenario->kontakt, jetzt: $szenario->jetzt());
    $planer->sageAb($termin, CancellationReason::Contact, jetzt: $szenario->jetzt());

    expect(fn () => $planer->verschiebe($termin, $szenario->vorschlag(20), jetzt: $szenario->jetzt()))
        ->toThrow(TerminNichtAenderbar::class);
});

it('verschiebt keinen bereits stattgefundenen Termin', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(0), $szenario->kontakt, jetzt: $szenario->jetzt());
    $planer->setzeStatus($termin, AppointmentStatus::Attended, jetzt: $termin->starts_at->addHour());

    expect(fn () => $planer->verschiebe($termin, $szenario->vorschlag(20), jetzt: $szenario->jetzt()))
        ->toThrow(TerminNichtAenderbar::class);
});
