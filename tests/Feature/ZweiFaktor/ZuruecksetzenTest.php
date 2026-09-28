<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Enums\AuditEvent;
use App\Enums\OperatorRole;
use App\Enums\Role;
use App\Enums\ZweiFaktorVerfahren;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

use Tests\Feature\ZweiFaktor\Zugang;

/*
|--------------------------------------------------------------------------
| WP-35, Abnahmekriterien 36 bis 40 -- zuruecksetzen, wenn das Handy weg ist
|--------------------------------------------------------------------------
|
| **Der zweite Faktor schuetzt auch vor Kolleginnen, die das Passwort
| kennen.** In einer kleinen Praxis ist das kein Sonderfall. Deshalb setzt
| den Faktor einer Inhaberin nur eine Inhaberin zurueck (abgeleitet, zu
| bestaetigen) und niemand den eigenen.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * @return array{0: User, 1: User}
 */
function inhaberinUndMitglied(Role $rolle = Role::Reception): array
{
    $inhaberin = Zugang::inhaberin();
    $praxis = Organization::query()->findOrFail($inhaberin->organization_id);

    $mitglied = User::factory()->fuer($praxis, $rolle)->mitAuthenticator()->create();

    return [$inhaberin, $mitglied];
}

/**
 * Der Befehl mit Rueckfrage. Pest\Laravel\artisan() liefert PendingCommand
 * **oder** int -- die Rueckfrage laesst sich nur am einen pruefen.
 *
 * @param  array<string, string>  $argumente
 */
function zuruecksetzBefehl(array $argumente): PendingCommand
{
    $befehl = artisan('mrs:zwei-faktor-zuruecksetzen', $argumente);
    assert($befehl instanceof PendingCommand);

    return $befehl;
}

it('setzt den zweiten Faktor eines Mitglieds der eigenen Praxis mit dem eigenen Passwort zurueck', function (): void {
    [$inhaberin, $mitglied] = inhaberinUndMitglied();

    actingAs($inhaberin)->delete(route('team.zwei-faktor', ['member' => $mitglied->uuid]), ['current_password' => 'falsch'])
        ->assertSessionHasErrors('current_password');
    expect($mitglied->fresh()?->hatZweiFaktor())->toBeTrue();

    actingAs($inhaberin)->delete(route('team.zwei-faktor', ['member' => $mitglied->uuid]), ['current_password' => 'password'])
        ->assertSessionHasNoErrors();
    expect($mitglied->fresh()?->hatZweiFaktor())->toBeFalse();

    // Eine fremde Praxis gibt es fuer diese Route nicht.
    $fremde = alsMandant(organisation('Andere Praxis'));
    $fremd = User::factory()->fuer($fremde, Role::Reception)->mitAuthenticator()->create();
    ohneMandant();

    actingAs($inhaberin)->delete(route('team.zwei-faktor', ['member' => $fremd->uuid]), ['current_password' => 'password'])
        ->assertNotFound();
    expect($fremd->fresh()?->hatZweiFaktor())->toBeTrue();
});

it('laesst den Faktor einer Inhaberin nur von einer Inhaberin zuruecksetzen, nie den eigenen und nie in der Impersonation', function (): void {
    [$inhaberin, $admin] = inhaberinUndMitglied(Role::Admin);
    $inhaberin->forceFill(['zwei_faktor_verfahren' => ZweiFaktorVerfahren::Email, 'zwei_faktor_bestaetigt_at' => CarbonImmutable::now()])->save();
    $praxis = Organization::query()->findOrFail($inhaberin->organization_id);

    actingAs($admin)->delete(route('team.zwei-faktor', ['member' => $inhaberin->uuid]), ['current_password' => 'password'])
        ->assertForbidden();

    actingAs($inhaberin)->delete(route('team.zwei-faktor', ['member' => $inhaberin->uuid]), ['current_password' => 'password'])
        ->assertSessionHasErrors('member');

    expect($inhaberin->fresh()?->hatZweiFaktor())->toBeTrue();

    alsMandant($praxis);
    $betreiber = User::factory()->superAdmin()->create();
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Ticket 4711, Handy verloren');
    ohneMandant();

    actingAs($betreiber)->withSession(impersonationSitzung($sitzung))
        ->delete(route('team.zwei-faktor', ['member' => $admin->uuid]), ['current_password' => 'password'])
        ->assertForbidden();

    expect($admin->fresh()?->hatZweiFaktor())->toBeTrue();
});

it('setzt in der Betreiberverwaltung den Faktor eines anderen Betreibers zurueck', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $kollege = User::factory()->customerSuccess()->mitAuthenticator()->create();
    $finanzen = User::factory()->finanzen()->create();

    actingAs($finanzen)->post(route('backoffice.betreiber.zwei-faktor', ['betreiber' => $kollege->uuid]), ['current_password' => 'password'])
        ->assertForbidden();

    actingAs($superAdmin)->post(route('backoffice.betreiber.zwei-faktor', ['betreiber' => $superAdmin->uuid]), ['current_password' => 'password'])
        ->assertSessionHasErrors('betreiber');

    actingAs($superAdmin)->post(route('backoffice.betreiber.zwei-faktor', ['betreiber' => $kollege->uuid]), ['current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($kollege->fresh()?->hatZweiFaktor())->toBeFalse();

    $eintrag = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::TwoFactorReset->value)->sole();
    expect($eintrag->organization_id)->toBeNull()
        ->and($eintrag->actor_user_id)->toBe($superAdmin->getKey());
});

it('setzt ueber die Konsole zurueck -- nur mit Begruendung, und die steht im Protokoll', function (): void {
    $betreiber = User::factory()->superAdmin()->mitAuthenticator()->create(['email' => 'letzter@mrs-beauty.test']);

    zuruecksetzBefehl(['email' => 'letzter@mrs-beauty.test'])->assertFailed();
    expect($betreiber->fresh()?->hatZweiFaktor())->toBeTrue();

    zuruecksetzBefehl(['email' => 'letzter@mrs-beauty.test', '--grund' => 'Telefon verloren, Ticket 12'])
        ->expectsConfirmation('Den zweiten Faktor von letzter@mrs-beauty.test zurücksetzen?', 'yes')
        ->assertSuccessful();

    expect($betreiber->fresh()?->hatZweiFaktor())->toBeFalse();

    $eintrag = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::TwoFactorReset->value)->sole();
    expect($eintrag->reason)->toBe('Telefon verloren, Ticket 12')
        ->and($eintrag->actor_label)->toBe('System')
        ->and($eintrag->organization_id)->toBeNull();

    [, $mitglied] = inhaberinUndMitglied();

    zuruecksetzBefehl(['email' => $mitglied->email, '--grund' => 'Handy verloren'])
        ->expectsConfirmation("Den zweiten Faktor von {$mitglied->email} zurücksetzen?", 'yes')
        ->assertSuccessful();

    expect($mitglied->fresh()?->hatZweiFaktor())->toBeFalse();
});

it('zeigt auf Teamseite und in der Betreiberverwaltung, wer einen zweiten Faktor hat', function (): void {
    [$inhaberin, $mitglied] = inhaberinUndMitglied();

    actingAs($inhaberin)->get(route('team.index'))
        ->assertInertia(fn ($seite) => $seite
            ->where('members', fn (Collection $mitglieder): bool => $mitglieder->pluck('zweiFaktor', 'uuid')->sortKeys()->all() === collect([
                (string) $inhaberin->uuid => null,
                (string) $mitglied->uuid => ZweiFaktorVerfahren::Authenticator->value,
            ])->sortKeys()->all()));

    $superAdmin = User::factory()->betreiber(OperatorRole::SuperAdmin)->create();
    $kollege = User::factory()->customerSuccess()->mitEmailCode()->create();

    actingAs($superAdmin)->get(route('backoffice.betreiber.index'))
        ->assertInertia(fn ($seite) => $seite
            ->where('betreiber', fn (Collection $konten): bool => $konten->pluck('zweiFaktor', 'uuid')->sortKeys()->all() === collect([
                (string) $superAdmin->uuid => null,
                (string) $kollege->uuid => ZweiFaktorVerfahren::Email->value,
            ])->sortKeys()->all()));
});
