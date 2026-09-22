<?php

declare(strict_types=1);

use App\Enums\AbsenceReason;
use App\Enums\Role;
use App\Enums\Weekday;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| WP-08, Abnahmekriterien 1 bis 3, 8 bis 12 und 16
|--------------------------------------------------------------------------
*/

// --- Mandantengrenze -------------------------------------------------------

it('zeigt nur Standorte der eigenen Organisation', function (): void {
    $eigene = alsMandant();
    $inhaberin = User::factory()->fuer($eigene, Role::Owner)->create();
    Location::factory()->create(['name' => 'Eigener Standort']);

    $fremde = organisation('Andere Praxis');
    app(TenantContext::class)->runAs($fremde, fn () => Location::factory()->create(['name' => 'Fremder Standort']));

    app(TenantContext::class)->set($eigene);

    actingAs($inhaberin)
        ->get(route('locations.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->has('locations', 1)
            ->where('locations.0.name', 'Eigener Standort')
        );
});

it('verhindert einen Behandler an einem fremden Standort auf Datenbankebene', function (): void {
    $eigene = alsMandant();
    $fremde = organisation('Andere Praxis');

    $fremderStandort = app(TenantContext::class)
        ->runAs($fremde, fn (): Location => Location::factory()->create());

    app(TenantContext::class)->set($eigene);
    $behandler = Practitioner::factory()->create();

    // Der zusammengesetzte Fremdschluessel (location_id, organization_id)
    // findet in locations keine passende Zeile.
    $behandler->locations()->attach($fremderStandort);
})->throws(QueryException::class);

it('verbindet kein Benutzerkonto einer fremden Organisation', function (): void {
    $eigene = alsMandant();
    $inhaberin = User::factory()->fuer($eigene, Role::Owner)->create();

    $fremde = organisation('Andere Praxis');
    $fremdesKonto = User::factory()->fuer($fremde, Role::Reception)->create();

    app(TenantContext::class)->set($eigene);

    actingAs($inhaberin)->post(route('practitioners.store'), [
        'first_name' => 'Anna',
        'last_name' => 'Beispiel',
        'user' => $fremdesKonto->uuid,
        'locations' => [],
    ])->assertSessionHasNoErrors();

    // Stillschweigend ignoriert statt verbunden -- und vor allem nicht
    // verbunden.
    expect(Practitioner::query()->firstOrFail()->user_id)->toBeNull();
});

// --- Zeitzone --------------------------------------------------------------

it('speichert keinen Standort ohne Zeitzone', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)->post(route('locations.store'), [
        'name' => 'Ohne Zone',
        'slug' => 'ohne-zone',
        'country' => 'DE',
    ])->assertSessionHasErrors('timezone');
});

it('speichert keine unbekannte Zeitzone', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)->post(route('locations.store'), [
        'name' => 'Tippfehler',
        'slug' => 'tippfehler',
        'timezone' => 'Europe/Berlinn',
        'country' => 'DE',
    ])->assertSessionHasErrors('timezone');
});

it('laesst zwei Standorte in verschiedenen Zonen zu', function (): void {
    alsMandant();

    Location::factory()->inZone('Europe/Berlin')->create();
    Location::factory()->inZone('Europe/Lisbon')->create();

    expect(Location::query()->pluck('timezone')->all())
        ->toEqualCanonicalizing(['Europe/Berlin', 'Europe/Lisbon']);
});

// --- Arbeitszeiten ---------------------------------------------------------

it('laesst mehrere Fenster an einem Wochentag zu', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();
    $behandler->locations()->attach($standort);

    // Vormittag und Nachmittag. Die Mittagspause ist die Luecke dazwischen.
    foreach ([['08:00', '12:00'], ['13:00', '17:00']] as [$beginn, $ende]) {
        actingAs($inhaberin)->post(route('workinghours.store', ['practitioner' => $behandler->uuid]), [
            'location' => $standort->uuid,
            'weekday' => Weekday::Montag->value,
            'starts_at' => $beginn,
            'ends_at' => $ende,
        ])->assertSessionHasNoErrors();
    }

    expect($behandler->workingHours()->count())->toBe(2);
});

it('laesst keine ueberschneidenden Fenster zu', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();
    $behandler->locations()->attach($standort);

    actingAs($inhaberin)->post(route('workinghours.store', ['practitioner' => $behandler->uuid]), [
        'location' => $standort->uuid,
        'weekday' => Weekday::Montag->value,
        'starts_at' => '09:00',
        'ends_at' => '17:00',
    ])->assertSessionHasNoErrors();

    // WP-10 wuerde daraus doppelte Slot-Zeilen materialisieren.
    actingAs($inhaberin)->post(route('workinghours.store', ['practitioner' => $behandler->uuid]), [
        'location' => $standort->uuid,
        'weekday' => Weekday::Montag->value,
        'starts_at' => '16:00',
        'ends_at' => '18:00',
    ])->assertSessionHasErrors('starts_at');

    expect($behandler->workingHours()->count())->toBe(1);
});

it('laesst kein Fenster zu, das endet bevor es beginnt', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();
    $behandler->locations()->attach($standort);

    actingAs($inhaberin)->post(route('workinghours.store', ['practitioner' => $behandler->uuid]), [
        'location' => $standort->uuid,
        'weekday' => Weekday::Montag->value,
        'starts_at' => '17:00',
        'ends_at' => '09:00',
    ])->assertSessionHasErrors('ends_at');
});

it('laesst keine Arbeitszeit an einem fremden Standort zu', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();

    // Kein attach: der Behandler arbeitet dort nicht.
    actingAs($inhaberin)->post(route('workinghours.store', ['practitioner' => $behandler->uuid]), [
        'location' => $standort->uuid,
        'weekday' => Weekday::Montag->value,
        'starts_at' => '09:00',
        'ends_at' => '17:00',
    ])->assertSessionHasErrors('location');
});

// --- Zeitraeume ------------------------------------------------------------

it('laesst keinen Zeitraum zu, der endet bevor er beginnt', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $behandler = Practitioner::factory()->create();

    actingAs($inhaberin)->post(route('absences.store', ['practitioner' => $behandler->uuid]), [
        'reason' => AbsenceReason::Vacation->value,
        'starts_at' => '2027-02-10 00:00:00',
        'ends_at' => '2027-02-01 00:00:00',
    ])->assertSessionHasErrors('ends_at');
});

// --- Loeschen --------------------------------------------------------------

it('deaktiviert einen Standort, statt ihn zu loeschen', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $standort = Location::factory()->create();
    $behandler = Practitioner::factory()->create();
    $behandler->locations()->attach($standort);

    actingAs($inhaberin)
        ->delete(route('locations.deactivate', ['location' => $standort->uuid]))
        ->assertSessionHasNoErrors();

    // Der Standort ist noch da -- sonst haette der Fremdschluessel mit
    // ON DELETE CASCADE die Zuordnung und spaeter die Termine mitgenommen.
    expect($standort->refresh()->is_active)->toBeFalse()
        ->and(Location::query()->count())->toBe(1)
        ->and($behandler->locations()->count())->toBe(1);

    actingAs($inhaberin)->put(route('locations.activate', ['location' => $standort->uuid]));

    expect($standort->refresh()->is_active)->toBeTrue();
});
