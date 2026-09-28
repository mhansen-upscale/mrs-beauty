<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Notifications\Anmeldecode;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
|--------------------------------------------------------------------------
| Entscheidung P7: nur Deutsch
|--------------------------------------------------------------------------
|
| Die Oberflaechentexte stehen in den Komponenten und lassen sich dort lesen.
| Was **Laravel** erzeugt, laesst sich nicht lesen, sondern nur pruefen:
| Validierungsmeldungen, Anmeldefehler und die beiden Mails der
| Anmeldestrecke. Genau die stehen hier.
|
*/

it('meldet Validierungsfehler auf Deutsch', function (): void {
    post(route('login'), ['email' => '', 'password' => ''])
        ->assertSessionHasErrors([
            'email' => 'E-Mail-Adresse ist erforderlich.',
            'password' => 'Passwort ist erforderlich.',
        ]);
});

it('meldet eine falsche Anmeldung auf Deutsch', function (): void {
    $organisation = alsMandant();
    User::factory()->fuer($organisation, Role::Owner)->create(['email' => 'chefin@praxis.test']);

    post(route('login'), ['email' => 'chefin@praxis.test', 'password' => 'falsch'])
        ->assertSessionHasErrors(['email' => 'E-Mail-Adresse oder Passwort stimmen nicht.']);
});

it('verschickt die Bestaetigungsmail auf Deutsch', function (): void {
    $organisation = alsMandant();
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->unverified()->create();

    $nachricht = (new VerifyEmail)->toMail($benutzer);

    expect($nachricht->subject)->toBe('E-Mail-Adresse bestätigen')
        ->and($nachricht->actionText)->toBe('E-Mail-Adresse bestätigen')
        ->and(implode(' ', $nachricht->introLines))->toContain('bestätigen Sie Ihre E-Mail-Adresse')
        ->and($nachricht->salutation)->toContain('Viele Grüße');
});

it('verschickt die Mail zum Zuruecksetzen auf Deutsch', function (): void {
    $organisation = alsMandant();
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    $nachricht = (new ResetPassword('merkmal'))->toMail($benutzer);

    expect($nachricht->subject)->toBe('Passwort zurücksetzen')
        ->and($nachricht->actionText)->toBe('Neues Passwort vergeben')
        // Kein Alarm, wo keiner noetig ist: wer das nicht angefordert hat,
        // muss nichts tun.
        ->and(implode(' ', $nachricht->outroLines))->toContain('nichts zu tun');
});

it('verschickt auch das Mailgeruest auf Deutsch', function (): void {
    $organisation = alsMandant();
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->unverified()->create();

    // Das Geruest kommt aus dem Framework und setzt Saetze selbst zusammen --
    // "If you're having trouble clicking the ... button". Es laesst sich nur
    // ueber lang/de.json ersetzen.
    $html = (string) (new VerifyEmail)->toMail($benutzer)->render();

    expect($html)->toContain('nicht anklicken lässt')
        ->and($html)->toContain('Alle Rechte vorbehalten.')
        ->and($html)->not->toContain('having trouble')
        ->and($html)->not->toContain('All rights reserved');
});

it('haelt keine englischen Sprachdateien vor', function (): void {
    // Der Rueckfall steht auf 'de' (APP_FALLBACK_LOCALE). Eine halb
    // uebersetzte Datei waere schlimmer als keine: die Meldungen wechselten
    // dann unvorhersehbar die Sprache.
    expect(config('app.locale'))->toBe('de')
        ->and(config('app.fallback_locale'))->toBe('de')
        ->and(is_dir(base_path('lang/de')))->toBeTrue()
        ->and(is_file(base_path('lang/de.json')))->toBeTrue()
        ->and(is_dir(base_path('lang/en')))->toBeFalse();
});

it('bietet die Registrierung nur an, wenn sie offen ist', function (): void {
    // Die Route gibt es immer, der Controller weist sie mit 404 ab. Ein
    // Hinweis auf eine Seite, die 404 liefert, gehoert nicht auf die
    // Anmeldung.
    config(['mrs.registration.self_service' => false]);

    get(route('login'))->assertInertia(fn ($seite) => $seite->where('canRegister', false));

    config(['mrs.registration.self_service' => true]);

    get(route('login'))->assertInertia(fn ($seite) => $seite->where('canRegister', true));
});

it('verschickt den Anmeldecode auf Deutsch und im Produktlayout (WP-35, AK 46)', function (): void {
    $organisation = alsMandant();
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    $nachricht = (new Anmeldecode('123456', 10, false))->toMail($benutzer);
    $html = (string) $nachricht->render();

    expect($nachricht->subject)->toBe('Ihr Anmeldecode')
        ->and(implode(' ', $nachricht->introLines))->toContain('123456')
        // Ohne Knopf stehen alle Zeilen oben -- der Rat zum Passwort auch.
        ->and(implode(' ', $nachricht->introLines))->toContain('ändern Sie es')
        ->and($nachricht->salutation)->toContain('Viele Grüße')
        // Das Layout der Praxis traegt ihr Logo und ihre Farbe. Diese Mail
        // kommt vom Produkt, nicht von der Praxis.
        ->and($nachricht->markdown)->toBe('notifications::email')
        ->and($html)->toContain('Alle Rechte vorbehalten.');

    $einrichtung = (new Anmeldecode('123456', 10, true))->toMail($benutzer);

    expect($einrichtung->subject)->toBe('Code zur Einrichtung des zweiten Faktors');
});

it('meldet einen falschen Code auf Deutsch (WP-35, AK 46)', function (): void {
    $organisation = alsMandant();
    User::factory()->fuer($organisation, Role::Owner)->mitAuthenticator()->create(['email' => 'chefin@praxis.test']);
    ohneMandant();

    post(route('login'), ['email' => 'chefin@praxis.test', 'password' => 'password']);

    post(route('login.zwei-faktor.pruefen'), ['code' => ''])
        ->assertSessionHasErrors(['code' => 'Code ist erforderlich.']);

    post(route('login.zwei-faktor.pruefen'), ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'Der Code stimmt nicht.']);
});
