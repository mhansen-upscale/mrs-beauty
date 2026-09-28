<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\GuardrailHit;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\PlatformMailSetting;
use App\Models\PlatformMailTemplate;
use App\Models\User;
use App\Notifications\Agentenalarm;
use App\Notifications\Anmeldecode;
use App\Notifications\Mailprobe;
use App\Notifications\TeamInvitation;
use App\Support\Markenstil;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

use Tests\Feature\Agent\Agentenaufbau;

/*
|--------------------------------------------------------------------------
| WP-37, Abnahmekriterien 2 bis 10, 18 bis 21 -- die Produktmails
|--------------------------------------------------------------------------
|
| **Links, Codes, Fristen und der Alarmsatz sind Kern, kein Text** (C17).
| Eine Vorlage des Betreibers schreibt davor und danach -- fuer alle Praxen
| zugleich, ab der naechsten Mail.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * @param  array<string, string>  $felder
 * @return array<string, string>
 */
function produkttext(array $felder = []): array
{
    return [
        'current_password' => 'password',
        'subject' => 'Ihr Code',
        'greeting' => 'Hallo {name},',
        'intro' => 'hier kommt Ihr Code.',
        'outro' => '',
        'salutation' => 'Ihr Team von {produkt}',
        ...$felder,
    ];
}

it('verlangt zum Speichern und Zuruecksetzen das eigene Passwort (AK 2)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'anmeldecode']), produkttext(['current_password' => 'falsch']))
        ->assertSessionHasErrors('current_password');

    actingAs($betreiber)->delete(route('backoffice.mails.destroy', ['mailart' => 'anmeldecode']), [])
        ->assertSessionHasErrors('current_password');

    expect(PlatformMailTemplate::query()->count())->toBe(0);
});

it('laesst Schaltflaeche, Link und Frist der Einladung in jeder Vorlage stehen (AK 3)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'einladung']), produkttext([
        'subject' => 'Willkommen bei {praxis}',
        'greeting' => 'Hallo,',
        'intro' => 'schön, dass Sie dabei sind.',
        'salutation' => 'Bis gleich',
    ]))->assertSessionHasNoErrors();

    $praxis = alsMandant(organisation('Praxis am Markt'));
    $einladung = Invitation::factory()->create(['email' => 'neu@praxis.test', 'role' => Role::Reception]);

    $mail = (new TeamInvitation($einladung, 'merkmal-123', $praxis->name))->toMail(new AnonymousNotifiable);

    expect((string) $mail->subject)->toBe('Willkommen bei Praxis am Markt')
        ->and($mail->actionText)->toBe('Einladung annehmen')
        ->and((string) $mail->actionUrl)->toContain('merkmal-123')
        ->and(implode(' ', $mail->introLines))->toContain('schön, dass Sie dabei sind.')
        ->and(implode(' ', $mail->introLines))->toContain('Ihre Rolle: ')
        ->and(implode(' ', $mail->outroLines))->toContain('Die Einladung gilt bis zum ');
});

it('nimmt im Betreff der Code-Mails keinen Platzhalter an und setzt den Code nie hinein (AK 4)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'anmeldecode']), produkttext(['subject' => 'Code für {name}']))
        ->assertSessionHasErrors('subject');

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'anmeldecode']), produkttext(['subject' => 'Ihr {code}']))
        ->assertSessionHasErrors('subject');

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'anmeldecode']), produkttext())->assertSessionHasNoErrors();

    $mail = (new Anmeldecode('987654', 10, false))->toMail($betreiber);

    expect((string) $mail->subject)->toBe('Ihr Code')
        ->and((string) $mail->subject)->not->toContain('987654')
        ->and(implode(' ', $mail->introLines))->toContain('**987654**')
        ->and(implode(' ', $mail->introLines))->toContain('ändern Sie es');
});

it('laesst den Inhalt einer Nachricht nie in den Alarm (AK 5)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'agentenalarm']), produkttext([
        'subject' => 'Übergabe',
        'intro' => 'Die Nachricht: {inhalt}',
    ]))->assertSessionHasErrors('intro');

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'agentenalarm']), produkttext([
        'subject' => 'Übergabe bei {praxis}',
        'greeting' => 'Guten Tag,',
        'intro' => 'bitte sehen Sie in den Posteingang.',
        'salutation' => 'Ihr Assistent',
    ]))->assertSessionHasNoErrors();

    $aufbau = new Agentenaufbau;
    $aufbau->nachricht('Seit gestern ist die Schwellung größer geworden.');

    $mail = (new Agentenalarm($aufbau->gespraech, GuardrailHit::Complication, 'Praxis am Markt'))->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    expect((string) $mail->subject)->toBe('Übergabe bei Praxis am Markt')
        ->and(implode(' ', $mail->introLines))->toContain('Der Inhalt steht **nicht** in dieser E-Mail')
        ->and($html)->not->toContain('Schwellung');
});

it('lehnt Markup in Vorlagen des Betreibers ab wie bei der Praxis (AK 6)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'passwort-zuruecksetzen']), produkttext([
        'subject' => 'Passwort',
        'intro' => '<img src="https://evil.test/x.png">',
    ]))->assertSessionHasErrors('intro');
});

it('laesst eine Vorlage fuer alle Praxen gelten, ab der naechsten Mail, und zuruecksetzen stellt den Standard her (AK 7, AK 8)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();
    $erste = User::factory()->fuer(organisation('Erste'), Role::Owner)->create();
    $zweite = User::factory()->fuer(organisation('Zweite'), Role::Owner)->create();

    expect((string) (new Anmeldecode('111111', 10, false))->toMail($erste)->subject)->toBe('Ihr Anmeldecode');

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'anmeldecode']), produkttext(['subject' => 'Ihr Code ist da']));

    expect((string) (new Anmeldecode('111111', 10, false))->toMail($erste)->subject)->toBe('Ihr Code ist da')
        ->and((string) (new Anmeldecode('222222', 10, false))->toMail($zweite)->subject)->toBe('Ihr Code ist da');

    actingAs($betreiber)->delete(route('backoffice.mails.destroy', ['mailart' => 'anmeldecode']), ['current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect((string) (new Anmeldecode('111111', 10, false))->toMail($erste)->subject)->toBe('Ihr Anmeldecode');
});

it('traegt Logo, Farbe und Fusstext des Betreibers, ohne "All rights reserved" (AK 9)', function (): void {
    $einstellung = PlatformMailSetting::aktuell();
    $einstellung->forceFill([
        'accent_color' => '#0B5394',
        'footer_text' => 'Mrs. Beauty GmbH · Hamburg',
        'imprint_url' => 'https://mrs-beauty.test/impressum',
        'logo_path' => 'plattform/mail/logo-1.png',
        'logo_mime' => 'image/png',
        'logo_version' => 1,
    ])->save();

    $person = User::factory()->fuer(alsMandant(), Role::Owner)->create();
    $html = (string) (new Anmeldecode('123456', 10, false))->toMail($person)->render();

    expect($html)->toContain('Mrs. Beauty GmbH · Hamburg')
        ->and($html)->toContain('https://mrs-beauty.test/impressum')
        ->and($html)->toContain(route('mail.logo', ['fassung' => 1]))
        ->and($html)->toContain('border-top: 4px solid '.Markenstil::hexFuer('#0B5394'))
        ->and($html)->not->toContain('All rights reserved');
});

it('nimmt als Logo nur PNG und JPEG und liefert es mit nosniff aus (AK 10)', function (): void {
    Storage::fake((string) config('mrs.attachments.disk'));
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->post(route('backoffice.versand.logo'), [
        'current_password' => 'password',
        'datei' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    ])->assertSessionHasErrors('datei');

    actingAs($betreiber)->post(route('backoffice.versand.logo'), [
        'current_password' => 'password',
        'datei' => UploadedFile::fake()->image('logo.png', 200, 60),
    ])->assertSessionHasNoErrors();

    $einstellung = PlatformMailSetting::aktuell();

    get(route('mail.logo', ['fassung' => $einstellung->logo_version]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Eine alte Fassung gibt es nicht mehr.
    get(route('mail.logo', ['fassung' => $einstellung->logo_version + 1]))->assertNotFound();
});

it('prueft die Farbe wie das Erscheinungsbild der Praxis (AK 10)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.versand.update'), ['current_password' => 'password', 'accent_color' => '#FFFFFF'])
        ->assertSessionHasErrors('accent_color');
});

it('zeigt die Vorschau, ohne zu verschicken, und schickt die Probe an die angemeldete Person (AK 18)', function (): void {
    Notification::fake();
    $betreiber = User::factory()->superAdmin()->create(['email' => 'chef@betreiber.test']);

    actingAs($betreiber)->post(route('backoffice.mails.vorschau', ['mailart' => 'einladung']), produkttext(['subject' => 'Neu im Team bei {praxis}', 'greeting' => 'Hallo,']))
        ->assertSessionHasNoErrors();

    actingAs($betreiber)->get(route('backoffice.mails.edit', ['mailart' => 'einladung']))
        ->assertInertia(fn ($seite) => $seite
            ->component('backoffice/Mailvorlage')
            ->where('vorschau.betreff', 'Neu im Team bei Praxis am Markt'));

    Notification::assertNothingSent();

    actingAs($betreiber)->post(route('backoffice.mails.probe', ['mailart' => 'einladung']), produkttext(['subject' => 'Neu im Team bei {praxis}', 'greeting' => 'Hallo,']))
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(Mailprobe::class, fn (Mailprobe $probe, array $kanaele, AnonymousNotifiable $an): bool => array_key_exists('chef@betreiber.test', (array) $an->routes['mail'])
        && (string) $probe->toMail($an)->subject === 'Probe: Neu im Team bei Praxis am Markt');
});

it('protokolliert Vorlagen ohne Organisation, mit Mailart und Feldnamen, nie mit Text (AK 19)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.mails.update', ['mailart' => 'anmeldecode']), produkttext(['intro' => 'Ein sehr eigener Satz.']));

    $eintrag = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::PlatformMailTemplateChanged->value)->firstOrFail();

    expect($eintrag->organization_id)->toBeNull()
        ->and($eintrag->context)->toBe(['mailart' => 'anmeldecode'])
        ->and($eintrag->changed_fields)->toContain('intro')
        ->and((string) json_encode([$eintrag->changed_fields, $eintrag->context]))->not->toContain('Ein sehr eigener Satz');
});

it('grueßt in der Einladung mit Umlaut und schreibt sonst die bisherigen Texte (AK 21)', function (): void {
    $praxis = alsMandant(organisation('Praxis am Markt'));
    $einladung = Invitation::factory()->create(['email' => 'neu@praxis.test', 'role' => Role::Reception]);
    $person = User::factory()->fuer($praxis, Role::Owner)->create();

    $einladungsmail = (new TeamInvitation($einladung, 'merkmal', $praxis->name))->toMail(new AnonymousNotifiable);
    $passwort = (new ResetPassword('merkmal'))->toMail($person);

    expect($einladungsmail->salutation)->toBe('Viele Grüße')
        ->and((string) $einladungsmail->subject)->toBe('Einladung zu Praxis am Markt')
        ->and((string) $passwort->subject)->toBe('Passwort zurücksetzen')
        ->and($passwort->greeting)->toBe('Guten Tag!')
        ->and($passwort->salutation)->toBe('Viele Grüße von '.config('app.name'));
});
