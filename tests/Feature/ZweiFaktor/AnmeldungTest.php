<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePraxisNichtGesperrt;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Anmeldecode;
use App\ZweiFaktor\Authenticator;
use App\ZweiFaktor\ZweiterFaktor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
| WP-35, Abnahmekriterien 14 bis 24 -- die Anmeldung an /login
|--------------------------------------------------------------------------
|
| **Nach dem Passwort ist noch niemand angemeldet.** Es gibt keine halbe
| Sitzung, die erst eine Middleware wieder einsperren muesste: angemeldet
| wird erst, wenn der zweite Faktor stimmt.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function mitApp(): User
{
    return Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());
}

function passwortSchritt(bool $merken = false): void
{
    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password', 'remember' => $merken])
        ->assertRedirect(route('login.zwei-faktor'));
}

it('meldet ohne zweiten Faktor an wie bisher', function (): void {
    $person = Zugang::inhaberin();

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($person);
});

it('meldet mit zweitem Faktor erst nach dem Code an und fuehrt zum Ziel', function (): void {
    $person = mitApp();

    // Das Ziel merkt sich die Anmeldung, bevor jemand angemeldet ist.
    get(route('profile.edit'))->assertRedirect(route('login'));

    passwortSchritt();
    assertGuest();

    get(route('login.zwei-faktor'))
        ->assertInertia(fn ($seite) => $seite->component('auth/ZweiFaktor')->where('verfahren', 'authenticator')->where('eingang', 'praxis'));

    post(route('login.zwei-faktor.pruefen'), ['code' => Zugang::appCode()])
        ->assertRedirect(route('profile.edit'));

    // Die neue Sitzungskennung laesst sich hier nicht pruefen: ohne Cookie
    // bekommt im Test jede Anfrage eine neue. Die ausstehende Anmeldung ist
    // jedenfalls weg.
    assertAuthenticatedAs($person);
    expect(session()->has('zwei_faktor_anmeldung'))->toBeFalse();
});

it('laesst Angemeldet bleiben erst nach dem Code wirken', function (): void {
    mitApp();

    $guard = Auth::guard('web');
    assert($guard instanceof SessionGuard);

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password', 'remember' => true])
        ->assertCookieMissing($guard->getRecallerName());

    post(route('login.zwei-faktor.pruefen'), ['code' => Zugang::appCode()])
        ->assertCookie($guard->getRecallerName());
});

it('nimmt einen Code aus dem Takt davor oder danach, nicht zwei daneben', function (int $versatz, bool $gilt): void {
    $person = mitApp();

    passwortSchritt();

    $antwort = post(route('login.zwei-faktor.pruefen'), ['code' => Zugang::appCode(versatz: $versatz)]);

    if ($gilt) {
        $antwort->assertRedirect(route('dashboard'));
        assertAuthenticatedAs($person);
    } else {
        $antwort->assertSessionHasErrors(['code' => 'Der Code stimmt nicht.']);
        assertGuest();
    }
})->with([
    'zwei davor' => [-2, false],
    'einer davor' => [-1, true],
    'jetzt' => [0, true],
    'einer danach' => [1, true],
    'zwei danach' => [2, false],
]);

it('laesst denselben App-Code nur einmal wirken, auch bei zwei Anmeldungen zugleich', function (): void {
    $person = mitApp();
    $code = Zugang::appCode();

    // Zwei Anfragen haben die Person geladen, bevor eine von beiden
    // geschrieben hat. Wer den letzten Schritt liest und danach schreibt,
    // laesst hier beide durch.
    $erste = User::query()->whereKey($person->getKey())->firstOrFail();
    $zweite = User::query()->whereKey($person->getKey())->firstOrFail();

    expect(app(Authenticator::class)->pruefe($erste, $code))->toBeTrue()
        ->and(app(Authenticator::class)->pruefe($zweite, $code))->toBeFalse();

    // Und ueber die Anmeldung: derselbe Code in einer neuen Sitzung.
    passwortSchritt();
    post(route('login.zwei-faktor.pruefen'), ['code' => $code])->assertSessionHasErrors('code');
    assertGuest();
});

it('verwirft die ausstehende Anmeldung nach fuenf falschen Codes', function (): void {
    mitApp();
    passwortSchritt();

    foreach (range(1, (int) config('mrs.zwei_faktor.max_versuche') - 1) as $_) {
        post(route('login.zwei-faktor.pruefen'), ['code' => '000000'])->assertSessionHasErrors('code');
    }

    post(route('login.zwei-faktor.pruefen'), ['code' => '000000'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Zu viele falsche Codes. Bitte melden Sie sich erneut an.']);

    // Auch der richtige hilft jetzt nicht mehr.
    post(route('login.zwei-faktor.pruefen'), ['code' => Zugang::appCode()])->assertRedirect(route('login'));
    assertGuest();
});

it('laesst die ausstehende Anmeldung verfallen', function (): void {
    mitApp();
    passwortSchritt();

    travel((int) config('mrs.zwei_faktor.anmeldung_gueltig_minuten') + 1)->minutes();

    post(route('login.zwei-faktor.pruefen'), ['code' => Zugang::appCode()])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Die Anmeldung ist abgelaufen. Bitte melden Sie sich erneut an.']);

    assertGuest();
});

it('drosselt je Person ueber alle Sitzungen', function (): void {
    $person = mitApp();
    $proSitzung = (int) config('mrs.zwei_faktor.max_versuche');
    $jeStunde = (int) config('mrs.zwei_faktor.fehlversuche_je_stunde');

    foreach (range(1, intdiv($jeStunde, $proSitzung)) as $_) {
        session()->flush();
        passwortSchritt();

        foreach (range(1, $proSitzung) as $__) {
            post(route('login.zwei-faktor.pruefen'), ['code' => '000000']);
        }
    }

    // Eine frische Sitzung mit dem richtigen Code -- und trotzdem nicht.
    session()->flush();
    passwortSchritt();
    post(route('login.zwei-faktor.pruefen'), ['code' => Zugang::appCode()])->assertRedirect(route('login'));
    assertGuest();

    travel(61)->minutes();

    session()->flush();
    passwortSchritt();
    post(route('login.zwei-faktor.pruefen'), ['code' => Zugang::appCode()])->assertRedirect(route('dashboard'));
    assertAuthenticatedAs($person);
});

it('fuehrt die Code-Seite ohne ausstehende Anmeldung zu ihrem Eingang', function (): void {
    get(route('login.zwei-faktor'))->assertRedirect(route('login'));
    get(route('backoffice.anmelden.code'))->assertRedirect(route('backoffice.anmelden'));
    post(route('login.zwei-faktor.pruefen'), ['code' => '123456'])->assertRedirect(route('login'));
});

it('bringt eine deaktivierte Person nicht bis zum Code und schickt ihr nichts', function (): void {
    Notification::fake();
    Zugang::inhaberin(fn ($f) => $f->mitEmailCode()->deaktiviert());

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => EnsureUserIsActive::MELDUNG]);

    assertGuest();
    expect(session()->has('zwei_faktor_anmeldung'))->toBeFalse();
    Notification::assertNothingSent();
});

it('bringt eine gesperrte Praxis nicht bis zum Code und schickt ihr nichts', function (): void {
    Notification::fake();
    $person = Zugang::inhaberin(fn ($f) => $f->mitEmailCode());

    $praxis = Organization::query()->findOrFail($person->organization_id);
    $praxis->suspended_at = CarbonImmutable::now();
    $praxis->save();

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => EnsurePraxisNichtGesperrt::MELDUNG]);

    assertGuest();
    Notification::assertNothingSent();
});

it('laesst eine ausstehende Anmeldung nach einem Passwortwechsel nicht gelten', function (): void {
    $person = mitApp();
    passwortSchritt();

    $person->forceFill(['password' => Hash::make('ein-ganz-anderes-passwort')])->save();

    post(route('login.zwei-faktor.pruefen'), ['code' => Zugang::appCode()])->assertRedirect(route('login'));
    assertGuest();
});

it('laesst eine ausstehende Anmeldung nach dem Zuruecksetzen nicht gelten', function (): void {
    Notification::fake();
    $person = Zugang::inhaberin(fn ($f) => $f->mitEmailCode());

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password']);
    $code = Zugang::codeAusMail($person);

    app(ZweiterFaktor::class)->setzeZurueck($person);

    post(route('login.zwei-faktor.pruefen'), ['code' => $code])->assertRedirect(route('login'));
    assertGuest();

    Notification::assertSentToTimes($person, Anmeldecode::class, 1);
});
