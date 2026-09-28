<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\GuardrailHit;
use App\Enums\NotificationKind;
use App\Enums\Role;
use App\Kanaele\Email\Postfach;
use App\Models\DemoRequest;
use App\Models\User;
use App\Notifications\Agentenalarm;
use App\Notifications\Demoanfrage;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Tests\Feature\Agent\Agentenaufbau;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| Jede Mail geht ueber die Warteschlange (28.09.2026)
|--------------------------------------------------------------------------
|
| Die Tests laufen sonst mit QUEUE_CONNECTION=sync -- dort fuehrt die
| Anfrage den Auftrag selbst aus, **mit ihrem Mandanten**. Ob ein Arbeiter
| ohne Mandant die Mail zustellen kann, zeigt nur eine echte Schlange. Hier
| deshalb die Datenbank als Schlange und ein Arbeiter, vor dem der Mandant
| vergessen ist.
|
| Und: was in der Schlange liegt, liegt in Redis und bei einem Fehler
| dauerhaft in failed_jobs. Merkmale, Codes und Personendaten stehen dort nur
| verschluesselt.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    config(['queue.default' => 'database']);
});

/** Ein Arbeiter ohne Mandanten, bis die Schlange leer ist -- oder ein Auftrag. */
function arbeitsgang(bool $einer = false): void
{
    ohneMandant();

    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'realtime,default',
        '--tries' => 1,
        '--sleep' => 0,
        // **Der Testprozess ist kein frischer Arbeiter.** Im Gesamtlauf
        // belegt er laengst mehr als die 128 MB der Vorgabe, und der
        // Arbeiter hoerte dann nach dem ersten Auftrag auf -- die zweite
        // Mail fehlte, aber nur in der ganzen Suite.
        '--memory' => 4096,
        '--timeout' => 0,
        ...($einer ? ['--once' => true] : ['--stop-when-empty' => true]),
    ]);
}

/** Was gerade in der Schlange liegt, als Text. */
function wartendeNutzlast(): string
{
    return DB::table('jobs')->pluck('payload')->implode("\n");
}

/**
 * Was zugestellt wurde (MAIL_MAILER=array).
 *
 * @return Collection<int, Email>
 */
function zugestellteMails(): Collection
{
    $transport = app('mail.manager')->mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return $transport->messages()
        ->map(fn (SentMessage $gesendet): mixed => $gesendet->getOriginalMessage())
        ->filter(fn (mixed $mail): bool => $mail instanceof Email)
        ->values();
}

function mailMitBetreff(string $betreff): Email
{
    $mail = zugestellteMails()->first(fn (Email $mail): bool => str_starts_with((string) $mail->getSubject(), $betreff));
    expect($mail)->toBeInstanceOf(Email::class, "Keine Mail mit dem Betreff \"{$betreff}\".");
    assert($mail instanceof Email);

    return $mail;
}

/** Die erste Gruppe eines Musters in einer Mail -- oder ein roter Test. */
function ausDerMail(string $muster, Email $mail): string
{
    $text = (string) $mail->getTextBody().' '.(string) $mail->getHtmlBody();

    expect(preg_match($muster, $text, $treffer))->toBe(1, "Kein Treffer fuer {$muster}.");

    return $treffer[1] ?? '';
}

it('schickt jede Notification verschluesselt ueber die Warteschlange', function (): void {
    $klassen = collect(glob(app_path('Notifications/*.php')) ?: [])
        ->map(fn (string $datei): string => 'App\\Notifications\\'.basename($datei, '.php'));

    expect($klassen)->not->toBeEmpty();

    // **Verschluesselt, jede.** Merkmal, Code, Passwortlink und Behandlung
    // stehen sonst im Klartext in Redis und failed_jobs. Und wer an einen
    // User geht, hat Rohbytes als Schluessel (A4): unverschluesselt bricht
    // json_encode() die Nutzlast -- gefunden an Alarm und Bestaetigung.
    foreach ($klassen as $klasse) {
        expect(is_subclass_of($klasse, ShouldQueue::class))->toBeTrue("{$klasse} geht nicht ueber die Warteschlange.")
            ->and(is_subclass_of($klasse, ShouldBeEncrypted::class))->toBeTrue("{$klasse} liegt unverschluesselt in der Schlange.");
    }
});

it('stellt die Einladung ohne Mandanten zu und laesst das Merkmal nicht im Klartext liegen', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    bezahltesAbo();
    ohneMandant();

    actingAs($inhaberin)->post(route('invitations.store'), ['email' => 'neu@praxis.test', 'role' => Role::Reception->value])
        ->assertSessionHasNoErrors();

    $nutzlast = wartendeNutzlast();
    expect(zugestellteMails())->toBeEmpty();

    arbeitsgang();

    $merkmal = ausDerMail('#/einladung/([A-Za-z0-9]+)#', mailMitBetreff('Einladung zu Demo-Praxis'));

    expect($merkmal)->not->toBe('')
        ->and($nutzlast)->not->toContain($merkmal);
});

it('stellt Passwortlink, Bestaetigung und Anmeldecode ohne Geheimnis in der Schlange zu', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    User::factory()->fuer($praxis, Role::Owner)->create(['email' => 'vergessen@praxis.test']);
    $neu = User::factory()->fuer($praxis, Role::Reception)->unverified()->create();
    User::factory()->fuer($praxis, Role::Admin)->mitEmailCode()->create(['email' => 'zweifaktor@praxis.test']);
    bezahltesAbo();
    ohneMandant();

    post(route('password.email'), ['email' => 'vergessen@praxis.test'])->assertSessionHasNoErrors();
    actingAs($neu)->post(route('verification.send'))->assertSessionHasNoErrors();
    auth()->logout();
    post(route('login'), ['email' => 'zweifaktor@praxis.test', 'password' => 'password'])->assertRedirect(route('login.zwei-faktor'));

    $nutzlast = wartendeNutzlast();
    expect(zugestellteMails())->toBeEmpty();

    arbeitsgang();

    $merkmal = ausDerMail('#reset-password/([A-Za-z0-9]+)#', mailMitBetreff('Passwort zurücksetzen'));

    mailMitBetreff('E-Mail-Adresse bestätigen');

    $code = ausDerMail('#\b(\d{6})\b#', mailMitBetreff('Ihr Anmeldecode'));

    expect($merkmal)->not->toBe('')
        ->and($code)->not->toBe('')
        ->and($nutzlast)->not->toContain($merkmal)
        ->and($nutzlast)->not->toContain($code);
});

it('stellt eine Terminnachricht ohne Mandanten zu, ohne Behandlung und Adresse in der Schlange', function (): void {
    alsMandant(organisation('Demo-Praxis'));
    bezahltesAbo();
    $attrappe = postfachAttrappe();
    praxispostfach(attrappe: $attrappe);
    $szenario = new Szenario;
    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, status: AppointmentStatus::Confirmed, jetzt: $szenario->jetzt());
    $behandlung = $termin->appointmentType->name;
    $adresse = (string) $szenario->kontakt->email;

    // Erst der Auftrag, der die Zeile beansprucht -- er reiht die Mail ein.
    arbeitsgang(einer: true);

    $nutzlast = wartendeNutzlast();

    expect($nutzlast)->toContain('SendQueuedNotifications')
        ->and($nutzlast)->not->toContain($behandlung)
        ->and($nutzlast)->not->toContain($adresse)
        // Die Zugangsdaten der Praxis entschluesselt erst der Kanal im
        // Arbeiter (WP-36 AK 26).
        ->and($nutzlast)->not->toContain('app-passwort')
        ->and(versandteMails($attrappe))->toBeEmpty();

    arbeitsgang();

    // Ueber das Postfach der Praxis, nicht ueber den Mailer der Plattform.
    expect(zugestellteMails())->toBeEmpty()
        ->and((string) (versandteMails($attrappe)[0] ?? null)?->getSubject())->toStartWith('Ihr Termin am');
});

it('schickt zwei Praxen in einem Arbeiterlauf je ueber ihren eigenen Server (WP-36 AK 26)', function (): void {
    $erste = alsMandant(organisation('Erste Praxis'));
    bezahltesAbo();
    $ersteAttrappe = new ArrayTransport;
    praxispostfach('empfang@erste.test', $ersteAttrappe)->forceFill(['smtp_host' => 'smtp.erste.test'])->save();
    $szenario = new Szenario;
    app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, status: AppointmentStatus::Confirmed, jetzt: $szenario->jetzt());

    alsMandant(organisation('Zweite Praxis'));
    bezahltesAbo();
    praxispostfach('empfang@zweite.test', $ersteAttrappe)->forceFill(['smtp_host' => 'smtp.zweite.test'])->save();
    $zweites = new Szenario;
    app(Terminplaner::class)->buche($zweites->vorschlag(), $zweites->kontakt, status: AppointmentStatus::Confirmed, jetzt: $zweites->jetzt());

    // Der Transport merkt sich den Host, ueber den er gebaut wurde -- so
    // zeigt sich, ob der Kanal fuer jede Praxis ihren eigenen Server nahm.
    $hosts = [];
    Mail::extend(Postfach::TRANSPORT, function (array $konfiguration) use ($ersteAttrappe, &$hosts): ArrayTransport {
        $hosts[] = $konfiguration['host'];

        return $ersteAttrappe;
    });

    arbeitsgang();

    $absender = array_map(fn (Email $mail): string => $mail->getFrom()[0]->getAddress(), versandteMails($ersteAttrappe));

    expect($absender)->toEqualCanonicalizing(['empfang@erste.test', 'empfang@zweite.test'])
        ->and($hosts)->toEqualCanonicalizing(['smtp.erste.test', 'smtp.zweite.test']);

    expect($erste->exists)->toBeTrue();
});

it('haelt eine endgueltig gescheiterte Terminnachricht als fehlgeschlagen fest, nicht als verschickt', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    bezahltesAbo();
    $szenario = new Szenario;
    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, status: AppointmentStatus::Confirmed, jetzt: $szenario->jetzt());

    // Ein Postfach, dessen Mailserver nicht antwortet -- echter Transport,
    // keine Attrappe.
    praxispostfach()->forceFill(['smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_encryption' => null])->save();
    Mail::extend(Postfach::TRANSPORT, fn (array $konfiguration): TransportInterface => app('mail.manager')
        ->createSymfonyTransport([...$konfiguration, 'transport' => 'smtp']));

    arbeitsgang(einer: true);

    arbeitsgang();

    alsMandant($praxis);
    $zeile = $termin->notifications()->where('kind', NotificationKind::Confirmation->value)->firstOrFail();

    expect($zeile->sent_at)->toBeNull()
        ->and($zeile->failed_at)->not->toBeNull()
        ->and($zeile->failure)->toBe('mail');
});

it('stellt den Alarm ohne Mandanten zu', function (): void {
    $aufbau = new Agentenaufbau;
    bezahltesAbo();
    $empfang = User::factory()->fuer($aufbau->organisation, Role::Reception)->create();

    Notification::send($empfang, new Agentenalarm($aufbau->gespraech, GuardrailHit::Complication, 'Demo-Praxis'));

    expect(zugestellteMails())->toBeEmpty();

    arbeitsgang();

    expect((string) mailMitBetreff('Bitte im Posteingang nachsehen')->getHtmlBody())->toContain((string) $aufbau->gespraech->uuid);
});

it('stellt die Demo-Anfrage verschluesselt und ohne Mandanten zu (WP-38 AK 18)', function (): void {
    $anfrage = DemoRequest::factory()->create(['practice_name' => 'Praxis am Hafen', 'email' => 'jana@praxis-am-hafen.test']);

    Notification::route('mail', 'vertrieb@example.test')->notify(new Demoanfrage($anfrage));

    // Die Nutzlast traegt nur Kennung und Zeit -- und auch die verschluesselt.
    expect(wartendeNutzlast())->not->toContain((string) $anfrage->uuid)
        ->and(wartendeNutzlast())->not->toContain('praxis-am-hafen');

    arbeitsgang();

    $mail = mailMitBetreff('Neue Demo-Anfrage');

    expect($mail->getTo()[0]->getAddress())->toBe('vertrieb@example.test')
        ->and((string) $mail->getHtmlBody())->toContain(route('backoffice.demoanfragen'))
        ->and((string) $mail->getHtmlBody())->not->toContain('Praxis am Hafen');
});

it('behaelt die Demo-Anfrage, wenn der Versand endgueltig scheitert (WP-38 AK 18)', function (): void {
    $anfrage = DemoRequest::factory()->create();

    // Kein hinterlegter Plattformserver, und der Rueckfall antwortet nicht.
    config([
        'mail.mailers.kaputt' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1],
        'mail.default' => 'kaputt',
    ]);

    Notification::route('mail', 'vertrieb@example.test')->notify(new Demoanfrage($anfrage));

    arbeitsgang();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DemoRequest::query()->whereKey($anfrage->getKey())->exists())->toBeTrue();
});
