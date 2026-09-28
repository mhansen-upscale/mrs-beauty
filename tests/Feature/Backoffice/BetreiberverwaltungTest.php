<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Enums\AuditEvent;
use App\Enums\OperatorRole;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Notifications\PasswortZuruecksetzen;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

use Symfony\Component\HttpFoundation\Response;

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

    Notification::assertSentTo($konto, PasswortZuruecksetzen::class);
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

    Notification::assertSentTo($konto, PasswortZuruecksetzen::class, function (PasswortZuruecksetzen $nachricht) use (&$merkmal): bool {
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

    $verwaltender = verwaltender();

    actingAs($verwaltender)
        ->post(route('backoffice.betreiber.deaktivieren', ['betreiber' => $inhaberin->uuid]), ['current_password' => 'password'])
        ->assertNotFound();

    betreiberLoeschen($verwaltender, $inhaberin)->assertNotFound();

    expect($inhaberin->fresh())->not->toBeNull();

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

/*
|--------------------------------------------------------------------------
| Nachtrag 28.09.2026 -- ein Betreiberkonto loeschen
|--------------------------------------------------------------------------
|
| Deaktivieren laesst sich rueckgaengig machen, Loeschen nicht. Was bleibt,
| ist das Protokoll: mit dem Namen der Handelnden, wie er beim Eintrag war.
|
*/

/**
 * @return TestResponse<Response>
 */
function betreiberLoeschen(User $wer, User $konto, string $passwort = 'password'): TestResponse
{
    return actingAs($wer)->delete(route('backoffice.betreiber.loeschen', ['betreiber' => $konto->uuid]), ['current_password' => $passwort]);
}

it('loescht ein Betreiberkonto endgueltig, samt offenem Passwortlink', function (): void {
    $konto = User::factory()->customerSuccess()->create(['name' => 'Clara Service', 'email' => 'clara@mrs-beauty.test']);
    Password::createToken($konto);

    betreiberLoeschen(verwaltender(), $konto)->assertRedirect()->assertSessionHasNoErrors();

    expect(User::query()->whereKey($konto->getKey())->exists())->toBeFalse()
        // Sonst setzte der Link aus der Mail ein Passwort fuer ein Konto, das
        // jemand spaeter unter derselben Adresse neu anlegt.
        ->and(DB::table('password_reset_tokens')->where('email', 'clara@mrs-beauty.test')->exists())->toBeFalse();
});

it('protokolliert das Loeschen mit Handelndem und ohne Personendaten des Kontos', function (): void {
    $konto = User::factory()->customerSuccess()->create(['name' => 'Clara Service', 'email' => 'clara@mrs-beauty.test']);
    $verwaltender = verwaltender();

    betreiberLoeschen($verwaltender, $konto)->assertSessionHasNoErrors();

    $eintrag = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::OperatorDeleted->value)->first();

    expect($eintrag?->actor_user_id)->toBe($verwaltender->getKey())
        ->and($eintrag?->subject_id)->toBe($konto->getKey())
        ->and($eintrag?->organization_id)->toBeNull()
        ->and($eintrag?->context)->toBe(['rolle' => OperatorRole::CustomerSuccess->value])
        // C5: weder Name noch Adresse des geloeschten Kontos.
        ->and(AuditLog::query()->withoutGlobalScopes()->get()->toJson())->not->toContain('Clara Service')
        ->and(AuditLog::query()->withoutGlobalScopes()->get()->toJson())->not->toContain('clara@mrs-beauty.test');
});

it('loescht nicht ohne das richtige eigene Passwort', function (): void {
    $konto = User::factory()->customerSuccess()->create();

    betreiberLoeschen(verwaltender(), $konto, 'falsch')->assertSessionHasErrors('current_password');

    expect($konto->fresh())->not->toBeNull();
});

it('laesst weder das eigene Konto noch den letzten aktiven Super-Admin loeschen', function (): void {
    $letzter = verwaltender();

    betreiberLoeschen($letzter, $letzter)->assertSessionHasErrors('betreiber');

    $zweite = verwaltender();

    // Auch mit einem zweiten Super-Admin: das eigene Konto loescht jemand
    // anderes.
    betreiberLoeschen($zweite, $zweite)->assertSessionHasErrors('betreiber');

    expect($letzter->fresh())->not->toBeNull()
        ->and($zweite->fresh())->not->toBeNull();
});

it('loescht einen deaktivierten Super-Admin, wenn ein aktiver bleibt', function (): void {
    $ruhender = User::factory()->superAdmin()->create(['deactivated_at' => CarbonImmutable::now()]);

    betreiberLoeschen(verwaltender(), $ruhender)->assertSessionHasNoErrors();

    expect($ruhender->fresh())->toBeNull();
});

it('beendet beim Loeschen eine laufende Impersonation', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $konto = User::factory()->customerSuccess()->create();
    app(Impersonation::class)->start($konto, $praxis, 'Ticket 4711, Termin fehlt');
    ohneMandant();

    betreiberLoeschen(verwaltender(), $konto)->assertSessionHasNoErrors();

    $sitzung = app(TenantContext::class)->runAs($praxis, fn (): ?ImpersonationSession => ImpersonationSession::query()->first());

    // Sonst stuende in der Praxis bis zum Ablauf "Support hat Zugriff" --
    // fuer ein Konto, das es nicht mehr gibt und das sich nie mehr abmeldet.
    expect($sitzung?->ended_at)->not->toBeNull()
        ->and($sitzung?->ended_reason)->toBe('account_deleted');
});

it('zeigt die Handlungen eines geloeschten Kontos weiter im Betreiberprotokoll', function (): void {
    $praxis = organisation('Demo-Praxis');
    $ehemalige = User::factory()->superAdmin()->create(['name' => 'Ehemalige Kollegin']);
    $bleibende = verwaltender();

    actingAs($ehemalige)->post(route('backoffice.sperren', ['organisation' => $praxis->uuid]), [
        'grund' => 'Zahlungsausfall nach dritter Mahnung',
        'current_password' => 'password',
    ])->assertSessionHasNoErrors();

    betreiberLoeschen($bleibende, $ehemalige)->assertSessionHasNoErrors();

    actingAs($bleibende)
        ->get(route('backoffice.protokoll'))
        ->assertInertia(fn ($seite) => $seite
            ->where('eintraege', fn (Collection $eintraege): bool => $eintraege->contains(
                fn (array $eintrag): bool => $eintrag['ereignis'] === AuditEvent::TenantSuspended->value
                    && $eintrag['praxis'] === 'Demo-Praxis'
                    && $eintrag['handelnde'] === 'Ehemalige Kollegin'
            ))
        );
});

it('laesst ein Betreiberkonto sich nicht in den Einstellungen selbst loeschen', function (): void {
    // Der Weg an der Betreiberverwaltung vorbei: sonst loeschte sich hier
    // auch der letzte Super-Admin.
    $letzter = verwaltender();

    actingAs($letzter)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect($letzter->fresh())->not->toBeNull();
});

it('legt den ersten Super-Admin auf der Konsole an', function (): void {
    expect(Artisan::call('mrs:betreiber', ['email' => 'erste@mrs-beauty.test', '--name' => 'Erste']))->toBe(0);

    $konto = User::query()->where('email', 'erste@mrs-beauty.test')->firstOrFail();

    expect($konto->betreiberRolle())->toBe(OperatorRole::SuperAdmin)
        ->and($konto->email_verified_at)->not->toBeNull();

    Notification::assertSentTo($konto, PasswortZuruecksetzen::class);
});

/**
 * Das Passwort aus der Ausgabe von `mrs:betreiber --passwort-ausgeben`.
 */
function ausgegebenesPasswort(): string
{
    preg_match('/Passwort: (\S+)/', Artisan::output(), $treffer);

    return $treffer[1] ?? '';
}

it('setzt auf der Konsole ein Passwort und gibt es einmal aus -- fuer Umgebungen ohne Mail', function (): void {
    expect(Artisan::call('mrs:betreiber', ['email' => 'staging@mrs-beauty.test', '--name' => 'Staging', '--passwort-ausgeben' => true]))->toBe(0);

    $passwort = ausgegebenesPasswort();
    $konto = User::query()->where('email', 'staging@mrs-beauty.test')->firstOrFail();

    expect(strlen($passwort))->toBe(24)
        ->and(Hash::check($passwort, (string) $konto->password))->toBeTrue()
        ->and($konto->betreiberRolle())->toBe(OperatorRole::SuperAdmin)
        ->and($konto->email_verified_at)->not->toBeNull();

    // Keine Mail -- es gibt keine, die ankaeme.
    Notification::assertNothingSent();

    // Das Passwort steht in der Ausgabe und nirgends sonst (C5).
    $eintrag = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::OperatorPasswordSet->value)->first();

    expect($eintrag?->subject_id)->toBe($konto->getKey())
        ->and($eintrag?->organization_id)->toBeNull()
        ->and($eintrag?->context)->toBe(['weg' => 'konsole'])
        ->and(AuditLog::query()->withoutGlobalScopes()->get()->toJson())->not->toContain($passwort);

    post(route('backoffice.anmelden.senden'), ['email' => 'staging@mrs-beauty.test', 'password' => $passwort])
        ->assertRedirect(route('dashboard'));
});

it('setzt einem vorhandenen Betreiber auf der Konsole ein neues Passwort', function (): void {
    $konto = User::factory()->customerSuccess()->create(['email' => 'vergessen@mrs-beauty.test']);

    expect(Artisan::call('mrs:betreiber', ['email' => 'vergessen@mrs-beauty.test', '--rolle' => 'customer_success', '--passwort-ausgeben' => true]))->toBe(0);

    $konto->refresh();

    expect(Hash::check(ausgegebenesPasswort(), (string) $konto->password))->toBeTrue()
        ->and(Hash::check('password', (string) $konto->password))->toBeFalse()
        ->and($konto->betreiberRolle())->toBe(OperatorRole::CustomerSuccess);

    Notification::assertNothingSent();
});

it('sagt auf der Konsole, wenn das Konto deaktiviert ist -- und laesst es so', function (): void {
    $konto = User::factory()->finanzen()->create(['email' => 'ruht@mrs-beauty.test', 'deactivated_at' => CarbonImmutable::now()]);

    expect(Artisan::call('mrs:betreiber', ['email' => 'ruht@mrs-beauty.test', '--rolle' => 'finanzen', '--passwort-ausgeben' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('deaktiviert')
        ->and($konto->refresh()->deactivated_at)->not->toBeNull();
});

it('macht auf der Konsole kein Praxiskonto zum Betreiber', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    User::factory()->fuer($praxis, Role::Owner)->create(['email' => 'inhaberin@praxis.test']);
    ohneMandant();

    expect(Artisan::call('mrs:betreiber', ['email' => 'inhaberin@praxis.test', '--rolle' => 'finanzen']))->toBe(1)
        ->and(Artisan::output())->toContain('gehört zu einer Praxis');

    // Auch nicht mit Passwort: die Inhaberin behaelt ihres.
    expect(Artisan::call('mrs:betreiber', ['email' => 'inhaberin@praxis.test', '--passwort-ausgeben' => true]))->toBe(1)
        ->and(Artisan::output())->not->toContain('Passwort:');
});
