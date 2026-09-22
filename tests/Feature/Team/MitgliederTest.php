<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| WP-04, Abnahmekriterien 5 bis 9
|--------------------------------------------------------------------------
*/

it('zeigt nur Personen der eigenen Organisation', function (): void {
    $eigene = alsMandant();
    $inhaberin = User::factory()->fuer($eigene, Role::Owner)->create(['name' => 'Eigene']);
    User::factory()->fuer($eigene, Role::Reception)->create(['name' => 'Kollegin']);

    $fremde = organisation('Andere Praxis');
    User::factory()->fuer($fremde, Role::Owner)->create(['name' => 'Fremde']);

    actingAs($inhaberin)
        ->get(route('team.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->has('members', 2)
            ->where('members.0.name', 'Eigene')
            ->where('members.1.name', 'Kollegin')
        );
});

it('laesst keine Rollenaenderung an einer fremden Person zu', function (): void {
    $eigene = alsMandant();
    $inhaberin = User::factory()->fuer($eigene, Role::Owner)->create();

    $fremde = organisation('Andere Praxis');
    $fremdePerson = User::factory()->fuer($fremde, Role::Reception)->create();

    actingAs($inhaberin)
        ->patch(route('team.update', ['member' => $fremdePerson->uuid]), ['role' => Role::Owner->value])
        ->assertNotFound();

    expect($fremdePerson->refresh()->role)->toBe(Role::Reception);
});

it('laesst die letzte Inhaberin ihre Rolle nicht abgeben', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)
        ->patch(route('team.update', ['member' => $inhaberin->uuid]), ['role' => Role::Admin->value])
        ->assertSessionHasErrors('role');

    expect($inhaberin->refresh()->role)->toBe(Role::Owner);
});

it('laesst eine Rollenaenderung zu, solange eine zweite Inhaberin bleibt', function (): void {
    $organisation = alsMandant();
    $eine = User::factory()->fuer($organisation, Role::Owner)->create();
    $andere = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($eine)
        ->patch(route('team.update', ['member' => $andere->uuid]), ['role' => Role::Admin->value])
        ->assertSessionHasNoErrors();

    expect($andere->refresh()->role)->toBe(Role::Admin);
});

it('laesst die letzte Inhaberin nicht deaktivieren', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $zweite = User::factory()->fuer($organisation, Role::Admin)->create();

    actingAs($zweite)
        ->delete(route('team.deactivate', ['member' => $inhaberin->uuid]))
        ->assertSessionHasErrors('member');

    expect($inhaberin->refresh()->isDeactivated())->toBeFalse();
});

it('laesst niemanden sich selbst deaktivieren', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)
        ->delete(route('team.deactivate', ['member' => $inhaberin->uuid]))
        ->assertSessionHasErrors('member');

    expect($inhaberin->refresh()->isDeactivated())->toBeFalse();
});

it('deaktiviert und reaktiviert ein Mitglied', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($inhaberin)->delete(route('team.deactivate', ['member' => $mitglied->uuid]));
    expect($mitglied->refresh()->isDeactivated())->toBeTrue();

    actingAs($inhaberin)->put(route('team.reactivate', ['member' => $mitglied->uuid]));
    expect($mitglied->refresh()->isDeactivated())->toBeFalse();
});

it('laesst eine deaktivierte Person sich nicht anmelden', function (): void {
    $organisation = alsMandant();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->deaktiviert()->create();

    // Die Anmeldung selbst gelingt technisch -- der naechste Aufruf wirft die
    // Person wieder heraus. Das ist der Punkt: eine Deaktivierung, die nur das
    // Anmeldeformular sperrt, laesst laufende Sitzungen weiterlaufen.
    actingAs($mitglied)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('wirft eine waehrend der Sitzung deaktivierte Person heraus', function (): void {
    $organisation = alsMandant();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($mitglied)->get(route('dashboard'))->assertOk();

    $mitglied->deactivated_at = now();
    $mitglied->save();

    actingAs($mitglied)->get(route('dashboard'))->assertRedirect(route('login'));
});
