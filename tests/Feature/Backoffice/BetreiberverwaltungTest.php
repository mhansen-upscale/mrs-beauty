<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\OperatorRole;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-34a, Abnahmekriterien 16 bis 18 -- die Betreiberverwaltung
|--------------------------------------------------------------------------
|
| Ein Betreiberkonto entsteht im Backoffice oder auf der Konsole, nie ueber
| die Registrierung. Das Passwort setzt die Person selbst.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Notification::fake();
});

function verwaltender(): User
{
    return User::factory()->superAdmin()->create();
}

it('legt ein Konto fuer Customer Success an und schickt den Passwortlink', function (): void {
    actingAs(verwaltender())
        ->post(route('backoffice.betreiber.store'), [
            'name' => 'Clara Service',
            'email' => 'clara@mrs-beauty.test',
            'rolle' => OperatorRole::CustomerSuccess->value,
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    $konto = User::query()->where('email', 'clara@mrs-beauty.test')->firstOrFail();

    expect($konto->betreiberRolle())->toBe(OperatorRole::CustomerSuccess)
        ->and($konto->organization_id)->toBeNull()
        ->and($konto->role)->toBeNull()
        // Die Routen verlangen `verified` -- und der Passwortlink beweist die
        // Adresse ohnehin.
        ->and($konto->email_verified_at)->not->toBeNull();

    Notification::assertSentTo($konto, ResetPassword::class);
});

it('fuehrt nach dem neuen Passwort zur Anmeldung der Betreiber', function (): void {
    actingAs(verwaltender())->post(route('backoffice.betreiber.store'), [
        'name' => 'Clara Service',
        'email' => 'clara@mrs-beauty.test',
        'rolle' => OperatorRole::CustomerSuccess->value,
        'current_password' => 'password',
    ]);

    auth()->guard('web')->logout();

    $konto = User::query()->where('email', 'clara@mrs-beauty.test')->firstOrFail();
    $merkmal = '';

    Notification::assertSentTo($konto, ResetPassword::class, function (ResetPassword $nachricht) use (&$merkmal): bool {
        $merkmal = $nachricht->token;

        return true;
    });

    post(route('password.store'), [
        'token' => $merkmal,
        'email' => 'clara@mrs-beauty.test',
        'password' => 'ein-langes-Passwort-2027',
        'password_confirmation' => 'ein-langes-Passwort-2027',
    ])->assertRedirect(route('backoffice.anmelden'));
});

it('nimmt keine Adresse an, die schon vergeben ist', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    User::factory()->fuer($praxis, Role::Owner)->create(['email' => 'inhaberin@praxis.test']);
    ohneMandant();

    actingAs(verwaltender())
        ->post(route('backoffice.betreiber.store'), [
            'name' => 'Doppelt',
            'email' => 'inhaberin@praxis.test',
            'rolle' => OperatorRole::Finanzen->value,
            'current_password' => 'password',
        ])
        ->assertSessionHasErrors('email');
});

it('aendert die Rolle und deaktiviert ein Konto', function (): void {
    $konto = User::factory()->customerSuccess()->create();
    $verwaltender = verwaltender();

    actingAs($verwaltender)
        ->patch(route('backoffice.betreiber.rolle', ['betreiber' => $konto->uuid]), [
            'rolle' => OperatorRole::Finanzen->value,
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    expect($konto->fresh()?->betreiberRolle())->toBe(OperatorRole::Finanzen);

    actingAs($verwaltender)
        ->post(route('backoffice.betreiber.deaktivieren', ['betreiber' => $konto->uuid]), ['current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($konto->fresh()?->isDeactivated())->toBeTrue();

    actingAs($verwaltender)
        ->post(route('backoffice.betreiber.reaktivieren', ['betreiber' => $konto->uuid]), ['current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($konto->fresh()?->isDeactivated())->toBeFalse();
});

it('behaelt den letzten aktiven Super-Admin', function (): void {
    $letzter = verwaltender();

    // Weder herabstufen ...
    actingAs($letzter)
        ->patch(route('backoffice.betreiber.rolle', ['betreiber' => $letzter->uuid]), [
            'rolle' => OperatorRole::CustomerSuccess->value,
            'current_password' => 'password',
        ])
        ->assertSessionHasErrors('rolle');

    // ... noch deaktivieren -- auch nicht von sich selbst.
    actingAs($letzter)
        ->post(route('backoffice.betreiber.deaktivieren', ['betreiber' => $letzter->uuid]), ['current_password' => 'password'])
        ->assertSessionHasErrors('betreiber');

    expect($letzter->fresh()?->betreiberRolle())->toBe(OperatorRole::SuperAdmin)
        ->and($letzter->fresh()?->isDeactivated())->toBeFalse();
});

it('laesst niemanden sich selbst deaktivieren', function (): void {
    $eine = verwaltender();
    verwaltender();

    actingAs($eine)
        ->post(route('backoffice.betreiber.deaktivieren', ['betreiber' => $eine->uuid]), ['current_password' => 'password'])
        ->assertSessionHasErrors('betreiber');

    expect($eine->fresh()?->isDeactivated())->toBeFalse();
});

it('verwaltet ueber diese Seite nur Betreiberkonten', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    ohneMandant();

    actingAs(verwaltender())
        ->post(route('backoffice.betreiber.deaktivieren', ['betreiber' => $inhaberin->uuid]), ['current_password' => 'password'])
        ->assertNotFound();

    actingAs(verwaltender())
        ->get(route('backoffice.betreiber.index'))
        ->assertInertia(fn ($seite) => $seite
            ->component('backoffice/Betreiber')
            ->has('betreiber', 2)
        );
});

it('protokolliert jede Aenderung an einem Betreiberkonto mit Handelndem', function (): void {
    $verwaltender = verwaltender();

    actingAs($verwaltender)->post(route('backoffice.betreiber.store'), [
        'name' => 'Clara Service',
        'email' => 'clara@mrs-beauty.test',
        'rolle' => OperatorRole::CustomerSuccess->value,
        'current_password' => 'password',
    ]);

    $konto = User::query()->where('email', 'clara@mrs-beauty.test')->firstOrFail();

    actingAs($verwaltender)->patch(route('backoffice.betreiber.rolle', ['betreiber' => $konto->uuid]), [
        'rolle' => OperatorRole::Finanzen->value,
        'current_password' => 'password',
    ]);
    actingAs($verwaltender)->post(route('backoffice.betreiber.deaktivieren', ['betreiber' => $konto->uuid]), ['current_password' => 'password']);
    actingAs($verwaltender)->post(route('backoffice.betreiber.reaktivieren', ['betreiber' => $konto->uuid]), ['current_password' => 'password']);

    foreach ([AuditEvent::OperatorCreated, AuditEvent::OperatorRoleChanged, AuditEvent::OperatorDeactivated, AuditEvent::OperatorReactivated] as $ereignis) {
        $eintrag = AuditLog::query()->withoutGlobalScopes()->where('event', $ereignis->value)->first();

        expect($eintrag)->not->toBeNull("{$ereignis->value} fehlt im Protokoll.")
            ->and($eintrag?->actor_user_id)->toBe($verwaltender->getKey())
            ->and($eintrag?->subject_id)->toBe($konto->getKey())
            ->and($eintrag?->organization_id)->toBeNull();
    }

    // Die Rolle darf mit Wert ins Protokoll, sie ist kein Personenbezug.
    expect(AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::OperatorRoleChanged->value)->first()?->context)
        ->toBe(['von' => OperatorRole::CustomerSuccess->value, 'nach' => OperatorRole::Finanzen->value]);
});

it('legt den ersten Super-Admin auf der Konsole an', function (): void {
    expect(Artisan::call('mrs:betreiber', ['email' => 'erste@mrs-beauty.test', '--name' => 'Erste']))->toBe(0);

    $konto = User::query()->where('email', 'erste@mrs-beauty.test')->firstOrFail();

    expect($konto->betreiberRolle())->toBe(OperatorRole::SuperAdmin)
        ->and($konto->email_verified_at)->not->toBeNull();

    Notification::assertSentTo($konto, ResetPassword::class);
});

it('macht auf der Konsole kein Praxiskonto zum Betreiber', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    User::factory()->fuer($praxis, Role::Owner)->create(['email' => 'inhaberin@praxis.test']);
    ohneMandant();

    expect(Artisan::call('mrs:betreiber', ['email' => 'inhaberin@praxis.test', '--rolle' => 'finanzen']))->toBe(1)
        ->and(Artisan::output())->toContain('gehört zu einer Praxis');
});
