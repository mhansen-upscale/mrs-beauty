<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
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
