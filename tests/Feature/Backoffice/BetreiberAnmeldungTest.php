<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Http\Middleware\BetreiberLeerlauf;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-34a, Abnahmekriterien 11 bis 15 -- die Anmeldung der Betreiber
|--------------------------------------------------------------------------
|
| **Ein eigener Eingang, derselbe Guard** (Entscheidung C14). Der zweite
| Faktor ist freiwillig (C16, WP-35), deshalb: strengere Drosselung, kein
| Angemeldet-Bleiben, Leerlauf-Abmeldung und das eigene Passwort vor jeder
| wirksamen Handlung.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function anmeldebetreiber(): User
{
    return User::factory()->superAdmin()->create(['email' => 'betrieb@mrs-beauty.test']);
}

/**
 * Die Eintraege eines Ereignisses -- ohne Organisation, also quer gelesen.
 *
 * @return Collection<int, AuditLog>
 */
function protokollzeilen(AuditEvent $ereignis): Collection
{
    return AuditLog::query()->withoutGlobalScopes()->where('event', $ereignis->value)->get();
}

it('nimmt an der eigenen Anmeldung nur Betreiber an', function (): void {
    $betreiber = anmeldebetreiber();

    post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($betreiber);
});

it('weist ein Praxiskonto an der Anmeldung der Betreiber ab', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    User::factory()->fuer($praxis, Role::Owner)->create(['email' => 'inhaberin@praxis.test']);
    ohneMandant();

    post(route('backoffice.anmelden.senden'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    assertGuest();
});

it('weist einen Betreiber an der Anmeldung der Praxen ab -- erst nach dem Passwort', function (): void {
    anmeldebetreiber();

    // **Dieselbe Meldung wie bei falschem Passwort.** Wer vorher abweist
    // oder anders antwortet, verraet, welche Adressen Betreiberkonten sind.
    post(route('login'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    assertGuest();

    post(route('login'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'falsch'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
});

it('drosselt nach drei Fehlversuchen', function (): void {
    anmeldebetreiber();

    foreach (range(1, (int) config('mrs.backoffice.login_versuche')) as $_) {
        post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'falsch']);
    }

    // Jetzt hilft auch das richtige Passwort nicht mehr.
    post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    assertGuest();

    // Nach der Sperrzeit wieder.
    travelTo(CarbonImmutable::now()->addMinutes((int) config('mrs.backoffice.login_sperrminuten') + 1));

    post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});

it('haelt einen Betreiber nie dauerhaft angemeldet', function (): void {
    anmeldebetreiber();

    $guard = Auth::guard('web');
    assert($guard instanceof SessionGuard);

    post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password', 'remember' => true])
        ->assertCookieMissing($guard->getRecallerName());
});

it('meldet einen untaetigen Betreiber ab', function (): void {
    $betreiber = anmeldebetreiber();
    $grenze = (int) config('mrs.backoffice.leerlauf_minuten');

    actingAs($betreiber)
        ->withSession([BetreiberLeerlauf::SESSION_KEY => CarbonImmutable::now()->subMinutes($grenze - 1)->getTimestamp()])
        ->get(route('dashboard'))
        ->assertOk();

    actingAs($betreiber)
        ->withSession([BetreiberLeerlauf::SESSION_KEY => CarbonImmutable::now()->subMinutes($grenze + 1)->getTimestamp()])
        ->get(route('dashboard'))
        ->assertRedirect(route('backoffice.anmelden'));

    assertGuest();
});

it('laesst eine Praxis nicht aus der Leerlauf-Abmeldung fallen', function (): void {
    // Die Frist gilt fuer Betreiber. Ein Praxisteam, das eine halbe Stunde
    // am Empfang steht, meldet sich nicht jedes Mal neu an.
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    ohneMandant();

    actingAs($empfang)
        ->withSession([BetreiberLeerlauf::SESSION_KEY => CarbonImmutable::now()->subHours(3)->getTimestamp()])
        ->get(route('dashboard'))
        ->assertOk();
});

it('laesst eine Sperre ohne das eigene Passwort nicht wirken', function (): void {
    $praxis = organisation('Demo-Praxis');
    $betreiber = anmeldebetreiber();

    actingAs($betreiber)
        ->post(route('backoffice.sperren', ['organisation' => $praxis->uuid]), ['grund' => 'Zahlungsausfall', 'current_password' => 'falsch'])
        ->assertSessionHasErrors('current_password');

    expect($praxis->fresh()?->suspended_at)->toBeNull();

    actingAs($betreiber)
        ->post(route('backoffice.sperren', ['organisation' => $praxis->uuid]), ['grund' => 'Zahlungsausfall', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($praxis->fresh()?->suspended_at)->not->toBeNull();
});

it('protokolliert Anmeldung und Fehlversuch -- nie die eingetippte Adresse', function (): void {
    $betreiber = anmeldebetreiber();

    post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'falsch']);
    post(route('backoffice.anmelden.senden'), ['email' => 'vertippt@nirgends.test', 'password' => 'falsch']);
    post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password']);

    $anmeldung = protokollzeilen(AuditEvent::OperatorLoggedIn);
    $fehlversuche = protokollzeilen(AuditEvent::OperatorLoginFailed);

    expect($anmeldung)->toHaveCount(1)
        ->and($anmeldung->first()?->actor_user_id)->toBe($betreiber->getKey())
        ->and($anmeldung->first()?->organization_id)->toBeNull()
        ->and($fehlversuche)->toHaveCount(2)
        // Das Konto, wenn es eines gibt ...
        ->and($fehlversuche->pluck('subject_id')->filter()->values()->all())->toBe([$betreiber->getKey()])
        // ... sonst nur die Adresse des Rechners.
        ->and($fehlversuche->pluck('ip_address')->filter()->count())->toBe(2);

    // **C5**: ein Protokoll voller vertippter E-Mail-Adressen ist eine
    // Adressliste.
    $protokoll = (string) json_encode(DB::table('audit_logs')->get()->map(fn ($zeile) => array_map(
        fn (mixed $wert): mixed => is_string($wert) && ! mb_check_encoding($wert, 'UTF-8') ? bin2hex($wert) : $wert,
        (array) $zeile,
    )));

    expect($protokoll)->not->toContain('vertippt@nirgends.test');
    expect($protokoll)->not->toContain('betrieb@mrs-beauty.test');
});

it('fuehrt Gaeste im Backoffice zur eigenen Anmeldung', function (): void {
    get(route('backoffice.index'))->assertRedirect(route('backoffice.anmelden'));
    get(route('dashboard'))->assertRedirect(route('login'));
});

it('fuehrt einen Betreiber nach dem Abmelden zur eigenen Anmeldung', function (): void {
    actingAs(anmeldebetreiber())
        ->post(route('logout'))
        ->assertRedirect(route('backoffice.anmelden'));

    assertGuest();
});
