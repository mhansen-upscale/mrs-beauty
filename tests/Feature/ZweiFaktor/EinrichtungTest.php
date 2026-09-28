<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Enums\ZweiFaktorVerfahren;
use App\Models\User;
use App\Notifications\Anmeldecode;
use App\ZweiFaktor\Wiederherstellungscodes;
use App\ZweiFaktor\ZweiterFaktor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\ZweiFaktor\Zugang;

/*
|--------------------------------------------------------------------------
| WP-35, Abnahmekriterien 1 bis 13 -- die Einrichtung
|--------------------------------------------------------------------------
|
| **Ein Geheimnis zaehlt erst, wenn es bestaetigt ist.** Bis dahin liegt es
| in der Sitzung, nicht in der Datenbank -- ein halb eingerichteter Faktor,
| der schon gilt, sperrt die Person bei der naechsten Anmeldung aus.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

it('bietet die App erst nach dem Passwort an und schaltet sie erst mit einem gueltigen Code ein', function (): void {
    $person = Zugang::inhaberin();

    actingAs($person)->post(route('zwei-faktor.app'), ['current_password' => 'password'])->assertRedirect();

    actingAs($person)->get(route('zwei-faktor.edit'))
        ->assertInertia(fn ($seite) => $seite
            ->component('settings/ZweiFaktor')
            ->where('einrichtung.verfahren', ZweiFaktorVerfahren::Authenticator->value)
            ->where('einrichtung.qrCode', fn (string $bild): bool => str_starts_with($bild, 'data:image/svg+xml;base64,'))
            ->where('einrichtung.schluessel', fn (string $schluessel): bool => preg_match('/^([A-Z2-7]{4} ){7}[A-Z2-7]{4}$/', $schluessel) === 1));

    expect($person->fresh()?->hatZweiFaktor())->toBeFalse();

    $geheimnis = (string) session('zwei_faktor_einrichtung.geheimnis');

    actingAs($person)->post(route('zwei-faktor.app.bestaetigen'), ['code' => Zugang::appCode($geheimnis)])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($person->fresh()?->zweiFaktorVerfahren())->toBe(ZweiFaktorVerfahren::Authenticator);
});

it('verlangt fuer Einrichtung, Abschalten und neue Codes das eigene Passwort', function (): void {
    $ohne = Zugang::inhaberin();

    actingAs($ohne)->post(route('zwei-faktor.app'), ['current_password' => 'falsch'])->assertSessionHasErrors('current_password');
    actingAs($ohne)->post(route('zwei-faktor.email'), ['current_password' => 'falsch'])->assertSessionHasErrors('current_password');

    $mit = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator(), 'zweite@praxis.test');

    actingAs($mit)->post(route('zwei-faktor.codes'), ['current_password' => 'falsch'])->assertSessionHasErrors('current_password');
    actingAs($mit)->delete(route('zwei-faktor.destroy'), ['current_password' => 'falsch'])->assertSessionHasErrors('current_password');

    expect(session()->has('zwei_faktor_einrichtung'))->toBeFalse()
        ->and($mit->fresh()?->hatZweiFaktor())->toBeTrue();
});

it('haelt das Geheimnis bis zur Bestaetigung in der Sitzung', function (): void {
    $person = Zugang::inhaberin();

    actingAs($person)->post(route('zwei-faktor.app'), ['current_password' => 'password']);

    $spalten = fn (): array => (array) DB::table('users')->where('id', $person->getKey())
        ->first(['zwei_faktor_verfahren', 'zwei_faktor_geheimnis', 'zwei_faktor_bestaetigt_at']);

    expect(array_filter($spalten()))->toBe([]);

    actingAs($person)->post(route('zwei-faktor.app.bestaetigen'), ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'Der Code stimmt nicht. Stimmt die Uhrzeit Ihres Telefons?']);

    expect(array_filter($spalten()))->toBe([]);
});

it('zeigt die Wiederherstellungscodes genau einmal und speichert nur Hashes', function (): void {
    $person = Zugang::inhaberin();

    actingAs($person)->post(route('zwei-faktor.app'), ['current_password' => 'password']);
    actingAs($person)->post(route('zwei-faktor.app.bestaetigen'), ['code' => Zugang::appCode((string) session('zwei_faktor_einrichtung.geheimnis'))]);

    $codes = [];

    actingAs($person)->get(route('zwei-faktor.edit'))
        ->assertInertia(function ($seite) use (&$codes) {
            $seite->has('neueCodes', (int) config('mrs.zwei_faktor.wiederherstellungscodes'));
            $codes = $seite->toArray()['props']['neueCodes'];

            return $seite;
        });

    $hashes = $person->fresh()?->zwei_faktor_wiederherstellung;

    expect($hashes)->toHaveCount(8)
        ->and($hashes)->each->toMatch('/^[0-9a-f]{64}$/')
        ->and(array_intersect($codes, (array) $hashes))->toBe([]);

    // Beim naechsten Aufruf sind sie weg.
    actingAs($person)->get(route('zwei-faktor.edit'))
        ->assertInertia(fn ($seite) => $seite->where('neueCodes', null)->where('codesUebrig', 8));
});

it('entwertet mit neuen Codes alle alten', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());
    $alt = app(Wiederherstellungscodes::class)->erzeuge($person);

    actingAs($person)->post(route('zwei-faktor.codes'), ['current_password' => 'password'])->assertSessionHasNoErrors();

    $neu = session('zwei_faktor_codes');

    expect($neu)->toHaveCount(8)
        ->and(app(Wiederherstellungscodes::class)->loese($person->fresh() ?? $person, $alt[0]))->toBeFalse()
        ->and(app(Wiederherstellungscodes::class)->loese($person->fresh() ?? $person, $neu[0]))->toBeTrue();
});

it('schaltet das E-Mail-Verfahren erst mit dem Code an die eigene Adresse ein', function (): void {
    Notification::fake();
    $person = Zugang::inhaberin();

    actingAs($person)->post(route('zwei-faktor.email'), ['current_password' => 'password'])->assertSessionHasNoErrors();

    Notification::assertSentTo($person, Anmeldecode::class);
    expect($person->fresh()?->hatZweiFaktor())->toBeFalse();

    actingAs($person)->post(route('zwei-faktor.email.bestaetigen'), ['code' => '000000'])->assertSessionHasErrors('code');
    expect($person->fresh()?->hatZweiFaktor())->toBeFalse();

    actingAs($person)->post(route('zwei-faktor.email.bestaetigen'), ['code' => Zugang::codeAusMail($person)])->assertSessionHasNoErrors();
    expect($person->fresh()?->zweiFaktorVerfahren())->toBe(ZweiFaktorVerfahren::Email);
});

it('laesst beim Wechsel das alte Verfahren aktiv, bis das neue bestaetigt ist', function (): void {
    Notification::fake();
    $person = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());
    app(Wiederherstellungscodes::class)->erzeuge($person);

    actingAs($person)->post(route('zwei-faktor.email'), ['current_password' => 'password']);

    expect($person->fresh()?->zweiFaktorVerfahren())->toBe(ZweiFaktorVerfahren::Authenticator);

    actingAs($person)->post(route('zwei-faktor.email.bestaetigen'), ['code' => Zugang::codeAusMail($person)]);

    $danach = $person->fresh();

    expect($danach?->zweiFaktorVerfahren())->toBe(ZweiFaktorVerfahren::Email)
        ->and($danach?->zwei_faktor_geheimnis)->toBeNull()
        ->and($danach?->zwei_faktor_wiederherstellung)->toBeNull()
        ->and($danach?->zwei_faktor_letzter_schritt)->toBeNull();
});

it('loescht beim Abschalten Verfahren, Geheimnis und Codes', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());
    app(Wiederherstellungscodes::class)->erzeuge($person);

    actingAs($person)->delete(route('zwei-faktor.destroy'), ['current_password' => 'password'])->assertSessionHasNoErrors();

    $danach = $person->fresh();

    expect($danach?->hatZweiFaktor())->toBeFalse()
        ->and($danach?->zwei_faktor_verfahren)->toBeNull()
        ->and($danach?->zwei_faktor_geheimnis)->toBeNull()
        ->and($danach?->zwei_faktor_wiederherstellung)->toBeNull()
        ->and($danach?->zwei_faktor_bestaetigt_at)->toBeNull();
});

it('erneuert das remember_token beim Einschalten, Wechsel und Zuruecksetzen', function (): void {
    Notification::fake();
    $person = Zugang::inhaberin();
    $token = fn (): ?string => $person->fresh()?->getRememberToken();

    $vorher = $token();
    actingAs($person)->post(route('zwei-faktor.app'), ['current_password' => 'password']);
    actingAs($person)->post(route('zwei-faktor.app.bestaetigen'), ['code' => Zugang::appCode((string) session('zwei_faktor_einrichtung.geheimnis'))]);
    expect($token())->not->toBe($vorher);

    $vorher = $token();
    actingAs($person)->post(route('zwei-faktor.email'), ['current_password' => 'password']);
    actingAs($person)->post(route('zwei-faktor.email.bestaetigen'), ['code' => Zugang::codeAusMail($person)]);
    expect($token())->not->toBe($vorher);

    $vorher = $token();
    app(ZweiterFaktor::class)->setzeZurueck($person->fresh() ?? $person);
    expect($token())->not->toBe($vorher);
});

it('gibt Geheimnis, Codes und letzten Schritt nie an die Oberflaeche', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitAuthenticator());
    app(Wiederherstellungscodes::class)->erzeuge($person);

    actingAs($person)->get(route('zwei-faktor.edit'))
        ->assertInertia(fn ($seite) => $seite
            ->missing('auth.user.zwei_faktor_geheimnis')
            ->missing('auth.user.zwei_faktor_wiederherstellung')
            ->missing('auth.user.zwei_faktor_letzter_schritt')
            ->where('einrichtung', null))
        ->assertDontSee(Zugang::GEHEIMNIS);

    // Mit dem App-Schluessel verschluesselt, nie im Klartext.
    $roh = (string) DB::table('users')->where('id', $person->getKey())->value('zwei_faktor_geheimnis');

    expect($roh)->not->toContain(Zugang::GEHEIMNIS)
        ->and(decrypt($roh, false))->toBe(Zugang::GEHEIMNIS);
});

it('richtet einen Betreiber ohne Organisation genauso ein', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->post(route('zwei-faktor.app'), ['current_password' => 'password']);
    actingAs($betreiber)->post(route('zwei-faktor.app.bestaetigen'), ['code' => Zugang::appCode((string) session('zwei_faktor_einrichtung.geheimnis'))])
        ->assertSessionHasNoErrors();

    expect($betreiber->fresh()?->zweiFaktorVerfahren())->toBe(ZweiFaktorVerfahren::Authenticator);
});

it('laesst den zweiten Faktor waehrend einer Impersonation nicht aendern', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $betreiber = User::factory()->superAdmin()->mitAuthenticator()->create();
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Ticket 4711, Termin fehlt');
    ohneMandant();

    foreach ([
        ['post', 'zwei-faktor.app'],
        ['post', 'zwei-faktor.email'],
        ['post', 'zwei-faktor.codes'],
        ['delete', 'zwei-faktor.destroy'],
        ['post', 'zwei-faktor.hinweis'],
    ] as [$methode, $route]) {
        actingAs($betreiber)->withSession(impersonationSitzung($sitzung))
            ->{$methode}(route($route), ['current_password' => 'password'])
            ->assertForbidden();
    }

    expect($betreiber->fresh()?->zweiFaktorVerfahren())->toBe(ZweiFaktorVerfahren::Authenticator);
});

it('haelt die Adresse fest, solange das E-Mail-Verfahren laeuft', function (): void {
    $person = Zugang::inhaberin(fn ($f) => $f->mitEmailCode());

    actingAs($person)->patch(route('profile.update'), ['name' => 'Neu', 'email' => 'anders@praxis.test'])
        ->assertSessionHasErrors('email');

    actingAs($person)->patch(route('profile.update'), ['name' => 'Neu', 'email' => 'inhaberin@praxis.test'])
        ->assertSessionHasNoErrors();

    expect($person->fresh()?->email)->toBe('inhaberin@praxis.test')
        ->and($person->fresh()?->name)->toBe('Neu');
});
