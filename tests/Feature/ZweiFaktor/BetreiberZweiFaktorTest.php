<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Http\Middleware\BetreiberLeerlauf;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

use Tests\Feature\ZweiFaktor\Zugang;

/*
|--------------------------------------------------------------------------
| WP-35, Abnahmekriterien 25 bis 28 -- die Anmeldung an /backoffice/anmelden
|--------------------------------------------------------------------------
|
| Der Eingang des Betreibers bleibt, was WP-34a gebaut hat: nie dauerhaft,
| jede Anmeldung und jeder Fehlversuch im Protokoll. Der Code kommt dazu --
| und **erst nach** der Pruefung, ob das Konto hier ueberhaupt hinein darf.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function betreiberMitApp(): User
{
    return User::factory()->superAdmin()->mitAuthenticator()->create(['email' => 'betrieb@mrs-beauty.test']);
}

function betreibereintraege(AuditEvent $ereignis): int
{
    return AuditLog::query()->withoutGlobalScopes()->where('event', $ereignis->value)->whereNull('organization_id')->count();
}

it('meldet einen Betreiber erst nach dem Code an, nie dauerhaft, und startet dann den Leerlauf', function (): void {
    $betreiber = betreiberMitApp();

    $guard = Auth::guard('web');
    assert($guard instanceof SessionGuard);

    post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password', 'remember' => true])
        ->assertRedirect(route('backoffice.anmelden.code'));

    assertGuest();

    get(route('backoffice.anmelden.code'))
        ->assertInertia(fn ($seite) => $seite->component('auth/ZweiFaktor')->where('eingang', 'betreiber'));

    travel(3)->minutes();

    post(route('backoffice.anmelden.code.pruefen'), ['code' => Zugang::appCode()])
        ->assertRedirect(route('dashboard'))
        ->assertCookieMissing($guard->getRecallerName());

    assertAuthenticatedAs($betreiber);
    expect(session(BetreiberLeerlauf::SESSION_KEY))->toBe(CarbonImmutable::now()->getTimestamp());
});

it('protokolliert die Anmeldung erst nach dem Code und jeden falschen Code', function (): void {
    $betreiber = betreiberMitApp();

    post(route('backoffice.anmelden.senden'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password']);

    expect(betreibereintraege(AuditEvent::OperatorLoggedIn))->toBe(0);

    post(route('backoffice.anmelden.code.pruefen'), ['code' => '000000'])->assertSessionHasErrors('code');

    $fehlversuch = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::OperatorLoginFailed->value)->sole();

    expect($fehlversuch->organization_id)->toBeNull()
        ->and($fehlversuch->subject_id)->toBe($betreiber->getKey())
        ->and($fehlversuch->context)->toBe(['schritt' => 'code']);

    post(route('backoffice.anmelden.code.pruefen'), ['code' => Zugang::appCode()]);

    expect(betreibereintraege(AuditEvent::OperatorLoggedIn))->toBe(1);
});

it('verraet am falschen Eingang nicht, dass ein zweiter Faktor wartet', function (): void {
    Notification::fake();

    User::factory()->superAdmin()->mitEmailCode()->create(['email' => 'betrieb@mrs-beauty.test']);
    Zugang::inhaberin(fn ($f) => $f->mitEmailCode());

    // Dieselbe Antwort wie bei falschem Passwort -- kein Code-Schritt, keine Mail.
    post(route('login'), ['email' => 'betrieb@mrs-beauty.test', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    post(route('backoffice.anmelden.senden'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    assertGuest();
    expect(session()->has('zwei_faktor_anmeldung'))->toBeFalse();
    Notification::assertNothingSent();
});

it('nimmt eine ausstehende Anmeldung nur an ihrem eigenen Eingang an', function (): void {
    Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertRedirect(route('login.zwei-faktor'));

    get(route('backoffice.anmelden.code'))->assertRedirect(route('login.zwei-faktor'));

    post(route('backoffice.anmelden.code.pruefen'), ['code' => Zugang::appCode()])
        ->assertRedirect(route('login.zwei-faktor'));

    assertGuest();
});
