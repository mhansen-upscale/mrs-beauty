<?php

declare(strict_types=1);

use App\Benachrichtigung\Versand\Plattformversand;
use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\PlattformversandPruefen;
use App\Models\AuditLog;
use App\Models\PlatformMailSetting;
use App\Models\User;
use App\Notifications\Anmeldecode;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/*
|--------------------------------------------------------------------------
| WP-37, Abnahmekriterien 11 bis 17 -- der Mailserver der Plattform
|--------------------------------------------------------------------------
|
| **Ein Mailserver gilt erst, wenn eine Mail durchging** (B23). Bis dahin
| gilt `.env`; jede Aenderung macht ihn wieder ungeprueft; scheitert er
| spaeter, geht die Mail ueber `.env`, und die Stoerung steht im Backoffice.
| Benutzername und Passwort gehen nie zurueck.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Was ueber den Mailer aus `.env` hinausging (MAIL_MAILER=array).
 *
 * @return list<Email>
 */
function umgebungsmails(): array
{
    $transport = app('mail.manager')->mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return array_values(array_filter(
        $transport->messages()->map(fn (SentMessage $gesendet): mixed => $gesendet->getOriginalMessage())->all(),
        fn (mixed $mail): bool => $mail instanceof Email,
    ));
}

/**
 * @param  array<string, mixed>  $felder
 * @return array<string, mixed>
 */
function serverdaten(array $felder = []): array
{
    return [
        'current_password' => 'password',
        'smtp_host' => 'smtp.betreiber.test',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'versand@betreiber.test',
        'smtp_password' => 'geheimes-passwort',
        'from_address' => 'hallo@betreiber.test',
        'from_name' => 'Mrs. Beauty',
        'reply_to_address' => 'hilfe@betreiber.test',
        ...$felder,
    ];
}

/**
 * Ein hinterlegter und geprueft geltender Server.
 *
 * @param  array<string, mixed>  $felder
 */
function geltenderServer(array $felder = []): PlatformMailSetting
{
    $einstellung = PlatformMailSetting::aktuell();
    $einstellung->forceFill([
        'smtp_host' => 'smtp.betreiber.test',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'versand@betreiber.test',
        'smtp_password' => 'geheimes-passwort',
        'from_address' => 'hallo@betreiber.test',
        'from_name' => 'Mrs. Beauty',
        'smtp_version' => 3,
        'smtp_verified_version' => 3,
        'verified_at' => CarbonImmutable::now(),
        ...$felder,
    ])->save();

    return $einstellung;
}

function codeAn(User $person): void
{
    $person->notifyNow(new Anmeldecode('123456', 10, false));
}

it('schickt ohne Einstellung ueber `.env`, mit dem Absender aus `.env`', function (): void {
    $attrappe = plattformAttrappe();
    $person = User::factory()->fuer(alsMandant(), Role::Owner)->create();

    codeAn($person);

    [$mail] = umgebungsmails();

    expect($mail->getFrom()[0]->getAddress())->toBe(config('mail.from.address'))
        ->and(versandteMails($attrappe))->toBeEmpty();
});

it('laesst bis zur bestandenen Probe `.env` gelten (AK 12)', function (): void {
    $attrappe = plattformAttrappe();
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten())->assertSessionHasNoErrors();

    expect(PlatformMailSetting::aktuell()->serverGilt())->toBeFalse();

    codeAn($betreiber);

    expect(versandteMails($attrappe))->toBeEmpty()
        ->and(umgebungsmails())->toHaveCount(1);
});

it('schickt nach der Probe ueber den hinterlegten Server mit seinem Absender (WP-37 AK 12)', function (): void {
    $attrappe = plattformAttrappe();
    $betreiber = User::factory()->superAdmin()->create(['email' => 'chef@betreiber.test']);

    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten())->assertSessionHasNoErrors();
    actingAs($betreiber)->post(route('backoffice.versand.probe'))->assertSessionHasNoErrors();

    $einstellung = PlatformMailSetting::aktuell();

    // Die Probe ging ueber genau diesen Server -- und nur ueber ihn.
    expect($einstellung->serverGilt())->toBeTrue()
        ->and(versandteMails($attrappe))->toHaveCount(1)
        ->and(umgebungsmails())->toBeEmpty();

    codeAn($betreiber);

    $mail = versandteMails($attrappe)[1] ?? null;

    expect($mail?->getFrom()[0]->getAddress())->toBe('hallo@betreiber.test')
        ->and($mail?->getReplyTo()[0]->getAddress())->toBe('hilfe@betreiber.test')
        ->and((string) $mail?->getSubject())->toBe('Ihr Anmeldecode');
});

it('macht jeden geaenderten Server wieder ungeprueft (AK 13)', function (array $aenderung): void {
    geltenderServer();
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten(['smtp_password' => '', 'smtp_username' => '', ...$aenderung]))
        ->assertSessionHasNoErrors();

    expect(PlatformMailSetting::aktuell()->serverGilt())->toBeFalse();
})->with([
    'Host' => [['smtp_host' => 'smtp2.betreiber.test']],
    'Port' => [['smtp_port' => 465]],
    'Passwort' => [['smtp_password' => 'neues-passwort']],
    'Benutzer' => [['smtp_username' => 'anderer@betreiber.test']],
]);

it('laesst einen Server gelten, an dem nur Absender oder Aussehen geaendert wurden', function (): void {
    geltenderServer();
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten(['smtp_password' => '', 'smtp_username' => '', 'from_name' => 'Anderer Name', 'accent_color' => '#1F5D5B']))
        ->assertSessionHasNoErrors();

    expect(PlatformMailSetting::aktuell()->serverGilt())->toBeTrue();
});

it('zaehlt eine Probe nicht, wenn die Zugangsdaten inzwischen geaendert wurden', function (): void {
    plattformAttrappe();
    $einstellung = geltenderServer(['smtp_verified_version' => null]);

    $auftrag = new PlattformversandPruefen($einstellung->smtp_version, 'chef@betreiber.test');

    $einstellung->forceFill(['smtp_version' => $einstellung->smtp_version + 1])->save();

    $auftrag->handle(app(Plattformversand::class));

    expect(PlatformMailSetting::aktuell()->serverGilt())->toBeFalse();
});

it('wechselt den Server zwischen zwei Mails ohne Neustart (AK 14)', function (): void {
    $erste = new ArrayTransport;
    $zweite = new ArrayTransport;
    $hosts = [];

    Mail::extend(Plattformversand::TRANSPORT, function (array $konfiguration) use ($erste, $zweite, &$hosts): TransportInterface {
        $hosts[] = $konfiguration['host'];

        return $konfiguration['host'] === 'smtp.erster.test' ? $erste : $zweite;
    });

    $person = User::factory()->fuer(alsMandant(), Role::Owner)->create();

    geltenderServer(['smtp_host' => 'smtp.erster.test']);
    codeAn($person);

    geltenderServer(['smtp_host' => 'smtp.zweiter.test']);
    codeAn($person);

    expect($hosts)->toBe(['smtp.erster.test', 'smtp.zweiter.test'])
        ->and(versandteMails($erste))->toHaveCount(1)
        ->and(versandteMails($zweite))->toHaveCount(1);
});

it('schickt ueber `.env`, wenn der hinterlegte Server scheitert, und zeigt die Stoerung (AK 15)', function (): void {
    Mail::extend(Plattformversand::TRANSPORT, fn (): TransportInterface => new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('Verbindung abgelehnt fuer empfaenger@example.test');
        }

        public function __toString(): string
        {
            return 'kaputt://';
        }
    });

    geltenderServer();
    $person = User::factory()->fuer(alsMandant(), Role::Owner)->create();

    codeAn($person);

    [$mail] = umgebungsmails();
    $einstellung = PlatformMailSetting::aktuell();

    expect($mail->getFrom()[0]->getAddress())->toBe(config('mail.from.address'))
        ->and($einstellung->last_error)->toBe('smtp_failed')
        ->and($einstellung->failed_at)->not->toBeNull()
        // Ohne Klartext des Servers -- er nannte die Empfaengeradresse.
        ->and((string) $einstellung->last_error)->not->toContain('@');

    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->get(route('backoffice.versand'))
        ->assertInertia(fn ($seite) => $seite->where('stand.stoerung', 'smtp_failed'));
});

it('legt Benutzername und Passwort verschluesselt ab und gibt sie nie zurueck (AK 11, AK 17)', function (): void {
    Queue::fake();
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten())->assertSessionHasNoErrors();
    actingAs($betreiber)->post(route('backoffice.versand.probe'));

    $roh = DB::table('platform_mail_settings')->first();

    expect((string) $roh?->smtp_password)->not->toContain('geheimes-passwort')
        ->and((string) $roh?->smtp_username)->not->toContain('versand@betreiber.test')
        ->and(PlatformMailSetting::aktuell()->smtp_password)->toBe('geheimes-passwort');

    $seite = actingAs($betreiber)->get(route('backoffice.versand'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())]);

    $inhalt = (string) $seite->getContent();

    expect(str_contains($inhalt, 'geheimes-passwort'))->toBeFalse()
        ->and(str_contains($inhalt, 'versand@betreiber.test'))->toBeFalse();

    $seite->assertJsonPath('props.server.passwortGesetzt', true)
        ->assertJsonPath('props.server.benutzerGesetzt', true);

    $protokoll = (string) json_encode(AuditLog::query()->withoutGlobalScopes()->get()->map->only(['changed_fields', 'context'])->all());

    expect($protokoll)->not->toContain('geheimes-passwort')
        ->and($protokoll)->not->toContain('versand@betreiber.test')
        ->and($protokoll)->toContain('smtp_password');

    Queue::assertPushed(PlattformversandPruefen::class, fn (PlattformversandPruefen $auftrag): bool => ! str_contains(serialize($auftrag), 'geheimes-passwort'));
});

it('laesst ein leeres Passwortfeld das gespeicherte stehen (AK 11)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten());
    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten(['smtp_password' => '', 'smtp_username' => '']));

    $einstellung = PlatformMailSetting::aktuell();

    expect($einstellung->smtp_password)->toBe('geheimes-passwort')
        ->and($einstellung->smtp_username)->toBe('versand@betreiber.test');
});

it('verlangt zum Speichern das eigene Passwort (WP-37 AK 2)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten(['current_password' => 'falsch']))
        ->assertSessionHasErrors('current_password');

    expect(PlatformMailSetting::query()->count())->toBe(0);
});

it('protokolliert Aenderungen ohne Organisation und nur mit Feldnamen (AK 19)', function (): void {
    $betreiber = User::factory()->superAdmin()->create();

    actingAs($betreiber)->put(route('backoffice.versand.update'), serverdaten());

    $eintrag = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::PlatformMailSettingsChanged->value)->firstOrFail();

    expect($eintrag->organization_id)->toBeNull()
        ->and($eintrag->changed_fields)->toContain('smtp_host')
        ->and((string) json_encode($eintrag->context))->not->toContain('smtp.betreiber.test');
});
