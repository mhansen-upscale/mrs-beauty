<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Practitioner;
use App\Models\User;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-11, offen: "Die Tagesansicht ist eine Liste je Behandler, kein
| Zeitraster. [...] Das gehoert zusammen mit der Wochenansicht in ein
| spaeteres Paket." -- seit dem 26.09.2026 da.
|--------------------------------------------------------------------------
|
| **Die Woche ist eine Woche der Praxis**, nicht des Servers: Montag 00:00 bis
| Montag 00:00 in der Ortszeit des Standorts. Wer in UTC schneidet, verliert
| den Sonntagabend oder bekommt den naechsten Montag dazu.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** Ein Termin zu einer Ortszeit in Berlin -- am Raster vorbei, weil es um die Anzeige geht. */
function terminAm(Szenario $szenario, string $ortszeit): void
{
    app(Terminplaner::class)->buche(
        $szenario->vorschlagAb(CarbonImmutable::parse($ortszeit, 'Europe/Berlin')->utc()->toIso8601String()),
        $szenario->kontakt,
        uebersteuern: true,
        jetzt: $szenario->jetzt(),
    );
}

it('zeigt in der Wochenansicht die Termine von Montag bis Sonntag', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    terminAm($szenario, '2027-01-11 09:00');   // Montag
    terminAm($szenario, '2027-01-13 10:00');   // Mittwoch
    terminAm($szenario, '2027-01-17 23:00');   // Sonntagabend
    terminAm($szenario, '2027-01-18 00:30');   // naechster Montag
    terminAm($szenario, '2027-01-10 23:30');   // Sonntag davor

    actingAs($empfang)
        ->get(route('appointments.index', [
            'date' => Szenario::TAG,
            'ansicht' => 'woche',
            'location' => $szenario->aufbau->standort->uuid,
        ]))
        ->assertInertia(fn ($seite) => $seite
            ->where('view', 'woche')
            ->where('days', ['2027-01-11', '2027-01-12', '2027-01-13', '2027-01-14', '2027-01-15', '2027-01-16', '2027-01-17'])
            ->has('appointments', 3)
            // Jeder Termin traegt seinen Tag -- in Ortszeit, nicht in UTC.
            ->where('appointments.0.date', '2027-01-11')
            ->where('appointments.2.date', '2027-01-17')
            ->where('appointments.2.starts_at', '23:00')
        );
});

it('bleibt ohne Angabe bei der Tagesansicht', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    terminAm($szenario, '2027-01-11 09:00');
    terminAm($szenario, '2027-01-13 10:00');

    actingAs($empfang)
        ->get(route('appointments.index', ['date' => Szenario::TAG, 'location' => $szenario->aufbau->standort->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('view', 'tag')
            ->has('appointments', 1)
        );
});

it('nennt den Zeitraum des Rasters aus den Arbeitszeiten', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario(von: '08:30:00', bis: '18:00:00');
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($empfang)
        ->get(route('appointments.index', ['date' => Szenario::TAG, 'ansicht' => 'woche', 'location' => $szenario->aufbau->standort->uuid]))
        ->assertInertia(fn ($seite) => $seite
            // Auf volle Stunden gerundet, nach aussen.
            ->where('hours.from', 8)
            ->where('hours.to', 18)
        );
});

it('dehnt das Raster fuer einen Termin ausserhalb der Arbeitszeit', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    // Uebersteuert, um 19:30 -- er muss trotzdem zu sehen sein.
    terminAm($szenario, '2027-01-13 19:30');

    actingAs($empfang)
        ->get(route('appointments.index', ['date' => Szenario::TAG, 'ansicht' => 'woche', 'location' => $szenario->aufbau->standort->uuid]))
        ->assertInertia(fn ($seite) => $seite->where('hours.to', 20));
});

it('zeigt einer Behandlerin auch in der Woche nur den eigenen Kalender', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;

    $behandlerin = User::factory()->fuer($organisation, Role::Practitioner)->create();
    $szenario->aufbau->behandler->user_id = $behandlerin->getKey();
    $szenario->aufbau->behandler->save();

    $andere = Practitioner::factory()->create();
    $andere->locations()->attach($szenario->aufbau->standort);

    terminAm($szenario, '2027-01-13 10:00');

    actingAs($behandlerin)
        ->get(route('appointments.index', ['date' => Szenario::TAG, 'ansicht' => 'woche', 'location' => $szenario->aufbau->standort->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->has('practitioners', 1)
            ->has('appointments', 1)
        );
});
