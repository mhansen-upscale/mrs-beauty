<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\CancellationReason;
use App\Models\AppointmentSlot;
use App\Models\AuditLog;
use App\Termine\TerminNichtAenderbar;
use App\Termine\Terminplaner;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-11, Abnahmekriterien 21 bis 28 -- Absagen und Status
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

it('gibt bei einer Absage die Slots frei', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());

    expect($termin->slots()->count())->toBe(6);

    $planer->sageAb($termin, CancellationReason::Contact, jetzt: $szenario->jetzt());

    expect($termin->slots()->count())->toBe(0)
        ->and(AppointmentSlot::query()->whereNotNull('appointment_id')->count())->toBe(0);
});

it('haelt Zeitpunkt und Grund der Absage fest', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());
    $planer->sageAb($termin, CancellationReason::Practice, jetzt: $szenario->jetzt());

    // Der Termin bleibt bestehen. Ohne die Zeile gaebe es keine Absagequote
    // und keine No-Show-Quote.
    expect($termin->status)->toBe(AppointmentStatus::Cancelled)
        ->and($termin->cancellation_reason)->toBe(CancellationReason::Practice)
        ->and($termin->cancelled_at?->equalTo($szenario->jetzt()))->toBeTrue()
        ->and($termin->exists)->toBeTrue();
});

it('belebt einen abgesagten Termin nicht wieder', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());
    $planer->sageAb($termin, CancellationReason::Contact, jetzt: $szenario->jetzt());

    // Die Zeit ist frei und moeglicherweise schon vergeben. Ein Wiederbeleben
    // waere eine Buchung ohne Verfuegbarkeitspruefung.
    expect(fn () => $planer->setzeStatus($termin, AppointmentStatus::Confirmed, jetzt: $szenario->jetzt()))
        ->toThrow(TerminNichtAenderbar::class);
});

it('setzt "Erschienen" nicht vor Terminbeginn', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());

    expect(fn () => $planer->setzeStatus($termin, AppointmentStatus::Attended, jetzt: $szenario->jetzt()))
        ->toThrow(TerminNichtAenderbar::class);
});

it('setzt "Erschienen" nach Terminbeginn', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());
    $planer->setzeStatus($termin, AppointmentStatus::Attended, jetzt: $termin->starts_at->addMinute());

    expect($termin->status)->toBe(AppointmentStatus::Attended);
});

it('behaelt bei "Erschienen" und "Nicht erschienen" die Slots', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());
    $planer->setzeStatus($termin, AppointmentStatus::NoShow, jetzt: $termin->starts_at->addHour());

    // Der Termin hat stattgefunden -- oder eben nicht. Die Zeit war belegt.
    expect($termin->slots()->count())->toBe(6);
});

it('korrigiert "Erschienen" und "Nicht erschienen" gegeneinander', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());
    $spaeter = $termin->starts_at->addHour();

    $planer->setzeStatus($termin, AppointmentStatus::NoShow, jetzt: $spaeter);
    $planer->setzeStatus($termin, AppointmentStatus::Attended, jetzt: $spaeter);

    expect($termin->status)->toBe(AppointmentStatus::Attended);

    // Aber absagen laesst sich ein stattgefundener Termin nicht.
    expect(fn () => $planer->sageAb($termin, CancellationReason::Contact, jetzt: $spaeter))
        ->toThrow(TerminNichtAenderbar::class);
});

it('schreibt jeden Statuswechsel ins Protokoll', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());
    $planer->setzeStatus($termin, AppointmentStatus::Attended, jetzt: $termin->starts_at->addHour());

    $eintraege = AuditLog::query()
        ->where('subject_type', $termin->getMorphClass())
        ->where('event', AuditEvent::Updated->value)
        ->get();

    expect($eintraege)->not->toBeEmpty()
        ->and($eintraege->pluck('changed_fields')->flatten()->all())->toContain('status');

    // Feldnamen, keine Werte -- ausser bei den ausdruecklich unbedenklichen
    // (Entscheidung C5). 'status' ist eines davon, der Kontakt nicht.
    expect(json_encode($eintraege->pluck('context')->all()))->toContain('attended');
});

it('verlangt fuer eine Absage einen Grund', function (): void {
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $termin = $planer->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());

    expect(fn () => $planer->setzeStatus($termin, AppointmentStatus::Cancelled, jetzt: $szenario->jetzt()))
        ->toThrow(TerminNichtAenderbar::class);
});
