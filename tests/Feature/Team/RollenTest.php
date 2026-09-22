<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| WP-04, Abnahmekriterien 1 bis 4
|--------------------------------------------------------------------------
*/

it('gibt der Inhaberin jede Faehigkeit', function (): void {
    expect(Role::Owner->abilities())->toEqualCanonicalizing(Ability::cases());
});

it('ordnet jede Faehigkeit mindestens einer Rolle zu', function (): void {
    // Ein neuer Ability-Fall, den niemand bekommt, waere ein totes Recht --
    // oder ein vergessenes. Beides soll auffallen.
    // array_diff() ueber Enum-Faelle geht nicht -- es wandelt in Zeichenketten
    // um, und ein Enum laesst sich nicht so umwandeln. Also ueber die Werte.
    $vergeben = collect(Role::cases())
        ->flatMap(fn (Role $rolle): array => $rolle->abilities())
        ->map(fn (Ability $ability): string => $ability->value)
        ->unique()
        ->all();

    $alle = array_map(fn (Ability $ability): string => $ability->value, Ability::cases());

    $fehlend = array_values(array_diff($alle, $vergeben));

    expect($fehlend)->toBeEmpty(
        'Diese Faehigkeiten hat keine Rolle: '.implode(', ', $fehlend)
    );
});

it('haelt jede Rolle an ihren Katalog', function (Role $rolle, array $erwartet): void {
    expect(array_map(fn (Ability $a): string => $a->value, $rolle->abilities()))
        ->toEqualCanonicalizing($erwartet);
})->with([
    'Empfang' => [Role::Reception, [
        'appointments.manage', 'calendar.own.view', 'waitlist.manage',
        'inbox.view', 'inbox.reply', 'contacts.manage',
    ]],
    'Behandlerin' => [Role::Practitioner, [
        'calendar.own.view', 'inbox.view',
    ]],
    'Marketing' => [Role::Marketing, [
        'campaigns.manage', 'insights.view', 'brandguide.manage',
    ]],
]);

it('beantwortet eine fehlende Faehigkeit mit 403', function (): void {
    $organisation = alsMandant();
    $benutzer = User::factory()->fuer($organisation)->create(['role' => Role::Practitioner]);

    actingAs($benutzer)->get(route('team.index'))->assertForbidden();
});

it('laesst die Teamverwaltung fuer die Verwaltung zu', function (): void {
    $organisation = alsMandant();
    $benutzer = User::factory()->fuer($organisation)->create(['role' => Role::Admin]);

    actingAs($benutzer)->get(route('team.index'))->assertOk();
});

it('gibt einer deaktivierten Person keine Faehigkeit', function (): void {
    $benutzer = User::factory()->create([
        'role' => Role::Owner,
        'deactivated_at' => now(),
    ]);

    expect($benutzer->hasAbility(Ability::ManageTeam))->toBeFalse();
});

it('gibt einer Person ohne Rolle keine Faehigkeit', function (): void {
    $benutzer = User::factory()->create(['role' => null]);

    expect($benutzer->hasAbility(Ability::ViewInbox))->toBeFalse();
});
