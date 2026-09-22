<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
|--------------------------------------------------------------------------
| WP-04, Abnahmekriterien 10 bis 17
|--------------------------------------------------------------------------
*/

/**
 * Legt eine Einladung an und liefert das Merkmal im Klartext zurueck.
 *
 * @return array{Invitation, string}
 */
function einladungMitMerkmal(Role $rolle = Role::Reception, string $email = 'neu@praxis.de'): array
{
    $merkmal = Invitation::erzeugeMerkmal();

    $einladung = new Invitation;
    $einladung->email = $email;
    $einladung->role = $rolle;
    $einladung->token_hash = Invitation::hashe($merkmal);
    $einladung->expires_at = now()->addDays(14);
    $einladung->save();

    return [$einladung, $merkmal];
}

it('legt die Einladung in der Organisation der einladenden Person an', function (): void {
    Notification::fake();

    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)
        ->post(route('invitations.store'), [
            'email' => 'neu@praxis.de',
            'role' => Role::Reception->value,
        ])
        ->assertSessionHasNoErrors();

    $einladung = Invitation::query()->firstOrFail();

    expect($einladung->organization_id)->toBe($organisation->getKey())
        ->and($einladung->role)->toBe(Role::Reception)
        ->and($einladung->invited_by_user_id)->toBe($inhaberin->getKey());

    Notification::assertSentOnDemand(TeamInvitation::class);
});

it('legt nur den Hash des Merkmals ab', function (): void {
    alsMandant();
    [$einladung, $merkmal] = einladungMitMerkmal();

    expect($einladung->token_hash)->not->toBe($merkmal)
        ->and($einladung->token_hash)->toBe(hash('sha256', $merkmal))
        ->and(strlen($einladung->token_hash))->toBe(64);
});

it('legt bei der Annahme die Person mit der eingeladenen Rolle an', function (): void {
    alsMandant();
    [$einladung, $merkmal] = einladungMitMerkmal(Role::Marketing, 'marketing@praxis.de');
    $organizationId = $einladung->organization_id;

    ohneMandant();

    post(route('invitations.accept', ['token' => $merkmal]), [
        'name' => 'Neue Kollegin',
        'password' => 'ein-langes-passwort',
        'password_confirmation' => 'ein-langes-passwort',
    ])->assertRedirect(route('dashboard', absolute: false));

    $benutzer = User::query()->where('email', 'marketing@praxis.de')->firstOrFail();

    expect($benutzer->role)->toBe(Role::Marketing)
        ->and($benutzer->organization_id)->toBe($organizationId)
        // Der Weg ueber das Postfach ist der Nachweis.
        ->and($benutzer->hasVerifiedEmail())->toBeTrue();

    expect(app(TenantContext::class)->acrossTenants(
        'Test der Annahme',
        fn (): ?Invitation => Invitation::query()->whereKey($einladung->getKey())->first()
    )?->accepted_at)->not->toBeNull();
});

it('laesst eine abgelaufene Einladung nicht annehmen', function (): void {
    alsMandant();
    [$einladung, $merkmal] = einladungMitMerkmal();
    $einladung->expires_at = now()->subDay();
    $einladung->save();

    ohneMandant();

    get(route('invitations.show', ['token' => $merkmal]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->component('auth/InvitationInvalid'));

    post(route('invitations.accept', ['token' => $merkmal]), [
        'name' => 'X', 'password' => 'ein-langes-passwort', 'password_confirmation' => 'ein-langes-passwort',
    ])->assertNotFound();
});

it('laesst eine widerrufene Einladung nicht annehmen', function (): void {
    alsMandant();
    [$einladung, $merkmal] = einladungMitMerkmal();
    $einladung->revoked_at = now();
    $einladung->save();

    ohneMandant();

    post(route('invitations.accept', ['token' => $merkmal]), [
        'name' => 'X', 'password' => 'ein-langes-passwort', 'password_confirmation' => 'ein-langes-passwort',
    ])->assertNotFound();
});

it('laesst eine bereits angenommene Einladung nicht erneut annehmen', function (): void {
    alsMandant();
    [$einladung, $merkmal] = einladungMitMerkmal();
    $einladung->accepted_at = now();
    $einladung->save();

    ohneMandant();

    post(route('invitations.accept', ['token' => $merkmal]), [
        'name' => 'X', 'password' => 'ein-langes-passwort', 'password_confirmation' => 'ein-langes-passwort',
    ])->assertNotFound();
});

it('laesst eine unbekannte Einladung nicht annehmen', function (): void {
    ohneMandant();

    get(route('invitations.show', ['token' => 'voellig-erfunden']))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->component('auth/InvitationInvalid'));
});

it('laesst niemanden zweimal einladen', function (): void {
    Notification::fake();

    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    einladungMitMerkmal(email: 'doppelt@praxis.de');

    actingAs($inhaberin)
        ->post(route('invitations.store'), [
            'email' => 'doppelt@praxis.de',
            'role' => Role::Reception->value,
        ])
        ->assertSessionHasErrors('email');
});

it('laesst kein bestehendes Teammitglied einladen', function (): void {
    Notification::fake();

    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    User::factory()->fuer($organisation, Role::Reception)->create(['email' => 'schon@praxis.de']);

    actingAs($inhaberin)
        ->post(route('invitations.store'), [
            'email' => 'schon@praxis.de',
            'role' => Role::Reception->value,
        ])
        ->assertSessionHasErrors('email');
});

it('erzeugt beim erneuten Senden ein neues Merkmal', function (): void {
    Notification::fake();

    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    [$einladung, $merkmal] = einladungMitMerkmal();

    actingAs($inhaberin)
        ->post(route('invitations.resend', ['invitation' => $einladung->uuid]))
        ->assertSessionHasNoErrors();

    expect($einladung->refresh()->token_hash)->not->toBe(Invitation::hashe($merkmal));

    // Das alte Merkmal gilt danach nicht mehr. Vorher abmelden: die
    // Annahmeroute liegt hinter 'guest' und wuerde sonst nur umleiten.
    auth()->logout();
    ohneMandant();
    post(route('invitations.accept', ['token' => $merkmal]), [
        'name' => 'X', 'password' => 'ein-langes-passwort', 'password_confirmation' => 'ein-langes-passwort',
    ])->assertNotFound();
});

it('widerruft eine Einladung, statt sie zu loeschen', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    [$einladung] = einladungMitMerkmal();

    actingAs($inhaberin)->delete(route('invitations.destroy', ['invitation' => $einladung->uuid]));

    expect($einladung->refresh()->revoked_at)->not->toBeNull()
        ->and(Invitation::query()->offen()->count())->toBe(0);
});
