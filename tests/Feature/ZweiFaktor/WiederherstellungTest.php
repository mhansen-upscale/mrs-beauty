<?php

declare(strict_types=1);

use App\Models\User;
use App\ZweiFaktor\Wiederherstellungscodes;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

use Tests\Feature\ZweiFaktor\Zugang;

/*
|--------------------------------------------------------------------------
| WP-35, Abnahmekriterien 34 und 35 -- die Wiederherstellungscodes
|--------------------------------------------------------------------------
|
| Das Telefon ist weg, die App mit ihm. Acht Codes, jeder einmal, und die
| Person sieht rechtzeitig, dass sie ausgehen.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

it('laesst mit einem Wiederherstellungscode ohne App hinein, gleich wie geschrieben', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());
    $codes = app(Wiederherstellungscodes::class)->erzeuge($person);

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertRedirect(route('login.zwei-faktor'));

    // Abgetippt vom Zettel: klein, mit Leerzeichen statt Bindestrichen.
    $abgetippt = ' '.strtolower(str_replace('-', ' ', $codes[3])).' ';

    post(route('login.zwei-faktor.pruefen'), ['code' => $abgetippt])->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($person);
});

it('laesst jeden Code einmal wirken und warnt, wenn sie ausgehen', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());
    $codes = app(Wiederherstellungscodes::class)->erzeuge($person);

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password']);
    post(route('login.zwei-faktor.pruefen'), ['code' => $codes[0]])->assertRedirect(route('dashboard'));
    post(route('logout'));

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password']);
    post(route('login.zwei-faktor.pruefen'), ['code' => $codes[0]])->assertSessionHasErrors('code');
    assertGuest();

    actingAs(User::query()->whereKey($person->getKey())->firstOrFail())->get(route('zwei-faktor.edit'))
        ->assertInertia(fn ($seite) => $seite->where('codesUebrig', 7)->where('codesWarnung', false));

    // Bis auf zwei verbraucht -- jetzt der Rat, neue zu erzeugen.
    foreach (array_slice($codes, 1, 5) as $code) {
        expect(app(Wiederherstellungscodes::class)->loese(User::query()->whereKey($person->getKey())->firstOrFail(), $code))->toBeTrue();
    }

    actingAs(User::query()->whereKey($person->getKey())->firstOrFail())->get(route('zwei-faktor.edit'))
        ->assertInertia(fn ($seite) => $seite->where('codesUebrig', 2)->where('codesWarnung', true));

    // Und direkt nach der Anmeldung mit dem vorletzten.
    post(route('logout'));
    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password']);
    post(route('login.zwei-faktor.pruefen'), ['code' => $codes[6]])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('hinweise', fn (array $hinweise): bool => str_contains(implode(' ', $hinweise), 'Nur noch 1 Wiederherstellungscode'));
});
