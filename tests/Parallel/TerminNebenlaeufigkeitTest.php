<?php

declare(strict_types=1);

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Contact;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Termine\Terminplaner;
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
| WP-11, Abnahmekriterium 36
|--------------------------------------------------------------------------
|
| "Zwei gleichzeitige Buchungen auf dieselbe Strecke erzeugen genau einen
|  Termin. Paralleler Test, nicht sequenziell."
|
| Derselbe Grund wie in NebenlaeufigkeitTest: RefreshDatabase umschliesst
| jeden Test mit einer Transaktion, und eine zweite Sitzung sieht davon
| nichts. Ein Test, der das nicht trennt, prueft eine Sperre gegen sich selbst.
|
*/

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);

    $organisation = Organization::factory()->create(['name' => 'Termine parallel']);

    app(TenantContext::class)->set($organisation);
});

afterEach(function (): void {
    DB::connection('mysql_zweit')->disconnect();
    app(TenantContext::class)->forget();
});

function terminVorschlag(Aufbau $aufbau): Slotvorschlag
{
    return app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $aufbau->art,
        von: CarbonImmutable::parse('2027-01-13 00:00:00', 'UTC'),
        bis: CarbonImmutable::parse('2027-01-14 00:00:00', 'UTC'),
        jetzt: CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC'),
    )[0];
}

it('laesst eine zweite Sitzung nicht an die Slots einer laufenden Buchung', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = terminVorschlag($aufbau);
    $kontakt = Contact::factory()->create();

    $zweite = DB::connection('mysql_zweit');
    $zweite->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    // Sitzung A sperrt die Strecke und haelt die Transaktion offen.
    $zweite->beginTransaction();

    $gesperrt = $zweite->table('appointment_slots')
        ->where('practitioner_id', $vorschlag->behandler->getKey())
        ->where('starts_at', '>=', $vorschlag->blockedFrom)
        ->where('starts_at', '<', $vorschlag->blockedUntil)
        ->orderBy('starts_at')
        ->lockForUpdate()
        ->get();

    expect($gesperrt)->toHaveCount(12);

    // Sitzung B bucht denselben Zeitraum.
    $fehler = null;

    try {
        app(Terminplaner::class)->buche(
            $vorschlag,
            $kontakt,
            jetzt: CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC'),
        );
    } catch (QueryException $ausnahme) {
        $fehler = $ausnahme;
    }

    $zweite->rollBack();

    expect($fehler)->not->toBeNull()
        ->and($fehler?->getMessage())->toContain('Lock wait timeout');

    // **Der wichtigere Teil.** Die Terminzeile entsteht vor der Belegung --
    // sonst haette sie keinen Schluessel, auf den die Slots zeigen koennen.
    // Scheitert die Belegung, muss die Transaktion sie mitnehmen. Sonst
    // stuende im Kalender ein Termin, der keine Zeit belegt.
    expect(Appointment::query()->count())->toBe(0);

    // Nach dem Rollback geht es.
    $termin = app(Terminplaner::class)->buche(
        $vorschlag,
        $kontakt,
        jetzt: CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC'),
    );

    expect($termin->slots()->count())->toBe(12);
});

it('erzeugt aus zwei Buchungsversuchen genau einen Termin', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $vorschlag = terminVorschlag($aufbau);
    $kontakt = Contact::factory()->create();
    $jetzt = CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');

    $erfolge = 0;
    $fehler = 0;

    foreach ([1, 2] as $versuch) {
        try {
            app(Terminplaner::class)->buche($vorschlag, $kontakt, jetzt: $jetzt);
            $erfolge++;
        } catch (Throwable) {
            $fehler++;
        }
    }

    expect($erfolge)->toBe(1)
        ->and($fehler)->toBe(1)
        ->and(Appointment::query()->count())->toBe(1);
});

it('macht auch die Uebersteuerung zu einem Datenbankfehler statt zu einer Doppelvergabe', function (): void {
    $aufbau = new Aufbau(von: '09:00:00', bis: '10:00:00', dauer: 60);
    $aufbau->erzeugeSlots('2027-01-13', '2027-01-13');

    $kontakt = Contact::factory()->create();
    $jetzt = CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');

    // 18:00 Ortszeit -- dort gibt es keine Slot-Zeilen. Die Uebersteuerung
    // legt sie an, und zwar mit insertOrIgnore gegen den Unique-Index.
    $vorschlag = Slotvorschlag::ab(
        $aufbau->art,
        $aufbau->behandler,
        $aufbau->standort,
        CarbonImmutable::parse('2027-01-13 17:00:00', 'UTC'),
    );

    $erster = app(Terminplaner::class)->buche($vorschlag, $kontakt, uebersteuern: true, jetzt: $jetzt);

    expect($erster->slots()->count())->toBe(12)
        ->and(AppointmentSlot::query()->where('starts_at', '>=', $vorschlag->blockedFrom)->count())->toBe(12);

    $fehler = null;

    try {
        app(Terminplaner::class)->buche($vorschlag, $kontakt, uebersteuern: true, jetzt: $jetzt);
    } catch (Throwable $ausnahme) {
        $fehler = $ausnahme;
    }

    // Die zweite Uebersteuerung legt keine zweiten Zeilen an -- der
    // Unique-Index (practitioner_id, starts_at) laesst es nicht zu -- und
    // findet die vorhandenen belegt.
    expect($fehler)->not->toBeNull()
        ->and(Appointment::query()->count())->toBe(1)
        ->and(AppointmentSlot::query()->where('starts_at', '>=', $vorschlag->blockedFrom)->count())->toBe(12);
});
