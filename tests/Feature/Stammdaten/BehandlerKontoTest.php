<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Practitioner;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Behandler und Benutzerkonto
|--------------------------------------------------------------------------
|
| Eine Behandlerin sieht unter Termine nur den Kalender des Behandlers, an
| dem ihr Konto haengt. Bis September 2026 liess sich diese Verbindung in der
| Oberflaeche nicht setzen -- und jedes Speichern des Behandlers loeste eine
| bestehende, weil das Formular das Feld nie mitschickte.
|
*/

/**
 * @param  array<string, mixed>  $daten
 * @return array<string, mixed>
 */
function behandlerFormular(Practitioner $behandler, array $daten = []): array
{
    return [
        'title' => $behandler->title,
        'first_name' => $behandler->first_name,
        'last_name' => $behandler->last_name,
        'locations' => [],
        ...$daten,
    ];
}

function inhaberinDer(Organization $organisation): User
{
    return User::factory()->fuer($organisation, Role::Owner)->create();
}

it('laesst die Verbindung stehen, wenn das Formular kein Kontofeld schickt', function (): void {
    $organisation = alsMandant();
    $konto = User::factory()->fuer($organisation, Role::Practitioner)->create();
    $behandler = Practitioner::factory()->create(['user_id' => $konto->getKey()]);

    actingAs(inhaberinDer($organisation))
        ->patch(route('practitioners.update', ['practitioner' => $behandler->uuid]), behandlerFormular($behandler, [
            'last_name' => 'Neuer Name',
        ]))
        ->assertSessionHasNoErrors();

    expect($behandler->fresh()?->user_id)->toBe($konto->getKey())
        ->and($behandler->fresh()?->last_name)->toBe('Neuer Name');
});

it('verbindet ein Konto mit Rolle Behandlerin', function (): void {
    $organisation = alsMandant();
    $konto = User::factory()->fuer($organisation, Role::Practitioner)->create();
    $behandler = Practitioner::factory()->create();

    actingAs(inhaberinDer($organisation))
        ->patch(route('practitioners.update', ['practitioner' => $behandler->uuid]), behandlerFormular($behandler, [
            'user' => $konto->uuid,
        ]))
        ->assertSessionHasNoErrors();

    expect($behandler->fresh()?->user_id)->toBe($konto->getKey());
});

it('verbindet schon beim Anlegen', function (): void {
    $organisation = alsMandant();
    $konto = User::factory()->fuer($organisation, Role::Practitioner)->create();

    actingAs(inhaberinDer($organisation))
        ->post(route('practitioners.store'), [
            'first_name' => 'Martina',
            'last_name' => 'Sauer',
            'user' => $konto->uuid,
            'locations' => [],
        ])
        ->assertSessionHasNoErrors();

    expect(Practitioner::query()->firstOrFail()->user_id)->toBe($konto->getKey());
});

it('loest die Verbindung, wenn ausdruecklich kein Konto gewaehlt ist', function (): void {
    $organisation = alsMandant();
    $konto = User::factory()->fuer($organisation, Role::Practitioner)->create();
    $behandler = Practitioner::factory()->create(['user_id' => $konto->getKey()]);

    actingAs(inhaberinDer($organisation))
        ->patch(route('practitioners.update', ['practitioner' => $behandler->uuid]), behandlerFormular($behandler, [
            'user' => null,
        ]))
        ->assertSessionHasNoErrors();

    expect($behandler->fresh()?->user_id)->toBeNull();
});

it('verbindet kein Konto einer anderen Rolle', function (): void {
    // Wer Termine verwaltet, sieht ohnehin alle -- die Verbindung haette nur
    // bei der Behandlerin eine Wirkung, und nur dort ist sie gemeint.
    $organisation = alsMandant();
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();
    $behandler = Practitioner::factory()->create();

    actingAs(inhaberinDer($organisation))
        ->patch(route('practitioners.update', ['practitioner' => $behandler->uuid]), behandlerFormular($behandler, [
            'user' => $empfang->uuid,
        ]))
        ->assertSessionHasErrors('user');

    expect($behandler->fresh()?->user_id)->toBeNull();
});

it('verbindet kein deaktiviertes Konto', function (): void {
    $organisation = alsMandant();
    $konto = User::factory()->fuer($organisation, Role::Practitioner)->deaktiviert()->create();
    $behandler = Practitioner::factory()->create();

    actingAs(inhaberinDer($organisation))
        ->patch(route('practitioners.update', ['practitioner' => $behandler->uuid]), behandlerFormular($behandler, [
            'user' => $konto->uuid,
        ]))
        ->assertSessionHasErrors('user');

    expect($behandler->fresh()?->user_id)->toBeNull();
});

it('verbindet ein Konto nicht mit zwei Behandlern', function (): void {
    // Sonst haengt "der eigene Kalender" am Zufall, welcher Behandler zuerst
    // gefunden wird.
    $organisation = alsMandant();
    $konto = User::factory()->fuer($organisation, Role::Practitioner)->create();
    Practitioner::factory()->create(['user_id' => $konto->getKey(), 'first_name' => 'Martina', 'last_name' => 'Sauer']);
    $zweiter = Practitioner::factory()->create();

    actingAs(inhaberinDer($organisation))
        ->patch(route('practitioners.update', ['practitioner' => $zweiter->uuid]), behandlerFormular($zweiter, [
            'user' => $konto->uuid,
        ]))
        ->assertSessionHasErrors('user');

    expect($zweiter->fresh()?->user_id)->toBeNull()
        ->and(session('errors')?->first('user'))->toContain('Martina Sauer');
});

it('laesst zwei Behandler mit demselben Konto auf Datenbankebene nicht zu', function (): void {
    $organisation = alsMandant();
    $konto = User::factory()->fuer($organisation, Role::Practitioner)->create();

    Practitioner::factory()->create(['user_id' => $konto->getKey()]);
    Practitioner::factory()->create(['user_id' => $konto->getKey()]);
})->throws(QueryException::class);

it('behaelt ein bereits verbundenes Konto einer anderen Rolle beim Speichern', function (): void {
    // Altbestand: vor der Pruefung liess sich jedes Konto verbinden. Wer den
    // Behandler danach nur umbenennt, soll dafuer keinen Fehler bekommen.
    $organisation = alsMandant();
    $inhaberin = inhaberinDer($organisation);
    $behandler = Practitioner::factory()->create(['user_id' => $inhaberin->getKey()]);

    actingAs($inhaberin)
        ->patch(route('practitioners.update', ['practitioner' => $behandler->uuid]), behandlerFormular($behandler, [
            'user' => $inhaberin->uuid,
            'first_name' => 'Anna',
        ]))
        ->assertSessionHasNoErrors();

    expect($behandler->fresh()?->user_id)->toBe($inhaberin->getKey());
});

it('bietet nur freie, aktive Behandlerinnen der eigenen Organisation an', function (): void {
    $organisation = alsMandant();
    $inhaberin = inhaberinDer($organisation);

    $frei = User::factory()->fuer($organisation, Role::Practitioner)->create(['name' => 'Freie Behandlerin']);
    $vergeben = User::factory()->fuer($organisation, Role::Practitioner)->create();
    Practitioner::factory()->create(['user_id' => $vergeben->getKey()]);
    User::factory()->fuer($organisation, Role::Practitioner)->deaktiviert()->create();
    User::factory()->fuer($organisation, Role::Reception)->create();

    $fremde = organisation('Andere Praxis');
    User::factory()->fuer($fremde, Role::Practitioner)->create();

    app(TenantContext::class)->set($organisation);

    actingAs($inhaberin)
        ->get(route('practitioners.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->has('accounts', 1)
            ->where('accounts.0.uuid', $frei->uuid)
            ->where('accounts.0.name', 'Freie Behandlerin')
            // Der Behandler zeigt, wer an ihm haengt -- auch wenn die Person
            // nicht mehr in der Auswahl steht.
            ->where('practitioners.0.user', $vergeben->uuid)
            ->where('practitioners.0.user_name', $vergeben->name)
        );
});
