<?php

declare(strict_types=1);

use App\Enums\HoldPurpose;
use App\Models\AppointmentSlot;
use App\Models\Organization;
use App\Models\SlotHold;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Verfuegbarkeit\Aufbau;
use Tests\TestCase;

// Ausdruecklich und ohne RefreshDatabase.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| docs/fachlogik/verfuegbarkeit.md, Testfaelle 16 und 17
|--------------------------------------------------------------------------
|
| "Zwei gleichzeitige Buchungen auf denselben Slot erzeugen genau einen Termin
|  und einen verstaendlichen Fehler. **Paralleler Test, nicht sequenziell.**"
|
| Ein sequenzieller Test bestaetigt nur, dass die zweite Buchung nach der
| ersten scheitert -- das tut sie auch ohne jede Sperre. Hier wird deshalb mit
| zwei echten Datenbanksitzungen gearbeitet: die eine haelt eine Sperre offen,
| waehrend die andere zuzugreifen versucht.
|
*/

beforeEach(function (): void {
    // Kein RefreshDatabase. Aufgeraeumt wird von Hand, damit echte
    // Transaktionen moeglich bleiben.
    Artisan::call('migrate:fresh', ['--force' => true]);

    // Die Factory legt den Schluesselsatz gleich mit an.
    $organisation = Organization::factory()->create(['name' => 'Nebenlaeufigkeit']);

    app(TenantContext::class)->set($organisation);
});

afterEach(function (): void {
    DB::connection('mysql_zweit')->disconnect();
    app(TenantContext::class)->forget();
});

function vorschlagFuer(Aufbau $aufbau): Slotvorschlag
{
    return app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $aufbau->art,
        von: CarbonImmutable::parse('2027-01-13 00:00:00', 'UTC'),
        bis: CarbonImmutable::parse('2027-01-14 00:00:00', 'UTC'),
        jetzt: CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC'),
    )[0];
}

it('laesst eine zweite Sitzung nicht an gesperrte Slots', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = vorschlagFuer($aufbau);

    $zweite = DB::connection('mysql_zweit');

    // Die zweite Sitzung wartet nicht lange auf eine Sperre -- sonst dauert
    // der Test 50 Sekunden.
    $zweite->statement('SET SESSION innodb_lock_wait_timeout = 1');

    // Sitzung A: Sperre aufbauen und **offen halten**.
    $zweite->beginTransaction();

    $gesperrt = $zweite->table('appointment_slots')
        ->where('practitioner_id', $vorschlag->behandler->getKey())
        ->where('starts_at', '>=', $vorschlag->blockedFrom)
        ->where('starts_at', '<', $vorschlag->blockedUntil)
        ->orderBy('starts_at')
        ->lockForUpdate()
        ->get();

    expect($gesperrt)->toHaveCount(12);

    // Sitzung B versucht dasselbe. Sie muss blockieren, nicht durchlaufen.
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    $fehler = null;

    try {
        app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);
    } catch (QueryException $ausnahme) {
        $fehler = $ausnahme;
    }

    $zweite->rollBack();

    // Ohne die Sperre waere hier kein Fehler entstanden -- und beide
    // Sitzungen haetten denselben Slot vergeben.
    expect($fehler)->not->toBeNull()
        ->and($fehler?->getMessage())->toContain('Lock wait timeout')
        ->and(SlotHold::query()->count())->toBe(0);

    // Nach dem Rollback geht es.
    $hold = app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);

    expect($hold->slots()->count())->toBe(12);
});

it('erzeugt aus zwei Versuchen genau einen Hold', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = vorschlagFuer($aufbau);

    $erfolge = 0;
    $fehler = 0;

    foreach ([1, 2] as $versuch) {
        try {
            app(SlotHalter::class)->halte($vorschlag, HoldPurpose::PublicBooking);
            $erfolge++;
        } catch (Throwable) {
            $fehler++;
        }
    }

    expect($erfolge)->toBe(1)
        ->and($fehler)->toBe(1)
        ->and(SlotHold::query()->count())->toBe(1);
});

it('macht Doppelvergabe zu einem Datenbankfehler', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorhandener = AppointmentSlot::query()->orderBy('starts_at')->firstOrFail();

    // Der Unique-Index (practitioner_id, starts_at) ist die zweite
    // Sicherung. Wenn die Sperre versagt, gewinnt die Datenbank -- nicht der
    // Zufall.
    $fehler = null;

    try {
        DB::table('appointment_slots')->insert([
            'id' => Uuid::generate(),
            'organization_id' => $vorhandener->getAttribute('organization_id'),
            'practitioner_id' => $vorhandener->getAttribute('practitioner_id'),
            'location_id' => $vorhandener->getAttribute('location_id'),
            'starts_at' => $vorhandener->starts_at->format('Y-m-d H:i:s'),
        ]);
    } catch (QueryException $ausnahme) {
        $fehler = $ausnahme;
    }

    expect($fehler)->not->toBeNull()
        ->and($fehler?->getMessage())->toContain('slots_behandler_zeit_unique');
});

it('erzeugt bei ueberlappenden Strecken keinen Deadlock', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '12:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $alle = app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $aufbau->art,
        von: CarbonImmutable::parse('2027-01-13 00:00:00', 'UTC'),
        bis: CarbonImmutable::parse('2027-01-14 00:00:00', 'UTC'),
        jetzt: CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC'),
    );

    // Zwei Strecken, die sich ueberschneiden: 09:00-10:00 und 09:30-10:30.
    $frueh = $alle[0];
    $spaet = $alle[6];

    expect($spaet->blockedFrom->lessThan($frueh->blockedUntil))->toBeTrue();

    $zweite = DB::connection('mysql_zweit');
    $zweite->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    // Sitzung A sperrt die spaetere Strecke.
    $zweite->beginTransaction();
    $zweite->table('appointment_slots')
        ->where('practitioner_id', $spaet->behandler->getKey())
        ->where('starts_at', '>=', $spaet->blockedFrom)
        ->where('starts_at', '<', $spaet->blockedUntil)
        ->orderBy('starts_at')
        ->lockForUpdate()
        ->get();

    // Sitzung B will die fruehere -- sie ueberschneidet sich und blockiert.
    // Entscheidend: sie blockiert und stirbt am Zeitlimit, sie stirbt **nicht**
    // an einem Deadlock. Beide sperren in aufsteigender starts_at-Reihenfolge.
    $fehler = null;

    try {
        app(SlotHalter::class)->halte($frueh, HoldPurpose::PublicBooking);
    } catch (QueryException $ausnahme) {
        $fehler = $ausnahme;
    }

    $zweite->rollBack();

    expect($fehler?->getMessage())->toContain('Lock wait timeout')
        ->and($fehler?->getMessage())->not->toContain('Deadlock');
});
