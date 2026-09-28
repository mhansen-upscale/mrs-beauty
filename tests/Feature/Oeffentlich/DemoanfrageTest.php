<?php

declare(strict_types=1);

use App\Benachrichtigung\Versand\PlattformMailkanal;
use App\Enums\DemoRequestStatus;
use App\Models\DemoRequest;
use App\Notifications\Demoanfrage;
use App\Oeffentlich\Formularmerkmal;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-38, Abnahmekriterien 12 bis 17 -- die Demo-Anfrage
|--------------------------------------------------------------------------
|
| **Eine Demo-Anfrage ist ein Datensatz, keine Mail.** Erst gespeichert, dann
| benachrichtigt -- und die Nachricht an den Vertrieb traegt nichts aus der
| Anfrage.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    config(['mrs.oeffentlich.demoanfragen.empfaenger' => 'vertrieb@example.test']);
});

/**
 * Ein ausgefuelltes Formular, dessen Seite vor einer Minute geladen wurde.
 *
 * @param  array<string, mixed>  $werte
 * @return array<string, mixed>
 */
function demoFormular(array $werte = []): array
{
    return [
        'name' => 'Dr. Jana Berger',
        'practice_name' => 'Praxis am Hafen',
        'email' => 'jana.berger@praxis-am-hafen.test',
        'phone' => '040 123456',
        'city' => 'Hamburg',
        'merkmal' => app(Formularmerkmal::class)->erzeuge(CarbonImmutable::now()->subMinute()),
        ...$werte,
    ];
}

it('legt eine Demo-Anfrage an und bestaetigt sie auf der Seite', function (): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular())
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertSessionHas('erfolg');

    $anfrage = DemoRequest::query()->sole();

    expect($anfrage->name)->toBe('Dr. Jana Berger')
        ->and($anfrage->practice_name)->toBe('Praxis am Hafen')
        ->and($anfrage->email)->toBe('jana.berger@praxis-am-hafen.test')
        ->and($anfrage->phone)->toBe('040 123456')
        ->and($anfrage->city)->toBe('Hamburg')
        ->and($anfrage->status)->toBe(DemoRequestStatus::New);
});

it('verlangt Name, Praxis und E-Mail', function (string $feld): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular([$feld => '']))->assertSessionHasErrors($feld);

    expect(DemoRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['name', 'practice_name', 'email']);

it('nimmt Telefon und Ort optional', function (): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular(['phone' => '', 'city' => '']))->assertSessionHasNoErrors();

    $anfrage = DemoRequest::query()->sole();

    expect($anfrage->phone)->toBeNull()->and($anfrage->city)->toBeNull();
});

it('prueft die E-Mail-Adresse', function (): void {
    post(route('demoanfrage.senden'), demoFormular(['email' => 'keine-adresse']))->assertSessionHasErrors('email');

    expect(DemoRequest::query()->count())->toBe(0);
});

it('fuehrt kein Freitextfeld und speichert keines', function (): void {
    Notification::fake();

    // Die Tabelle hat keinen Platz dafuer ...
    expect(Schema::getColumnListing('demo_requests'))->toEqualCanonicalizing([
        'id', 'name', 'practice_name', 'email', 'phone', 'city', 'status', 'status_changed_at', 'created_at', 'updated_at',
    ]);

    // ... und was jemand trotzdem mitschickt, faellt weg.
    post(route('demoanfrage.senden'), demoFormular(['nachricht' => 'Ich interessiere mich fuer Lippen']))->assertSessionHasNoErrors();

    $gespeichert = collect((array) DB::table('demo_requests')->sole())
        ->except('id')
        ->map(fn (mixed $wert): string => is_string($wert) && str_starts_with($wert, 'eyJ') ? Crypt::decryptString($wert) : (string) $wert)
        ->implode(' ');

    expect($gespeichert)->toContain('Praxis am Hafen')
        ->and($gespeichert)->not->toContain('Lippen');
});

it('legt Name, Praxis, E-Mail, Telefon und Ort nicht im Klartext ab', function (): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular())->assertSessionHasNoErrors();

    $zeile = (array) DB::table('demo_requests')->sole();

    foreach (['name' => 'Jana Berger', 'practice_name' => 'Praxis am Hafen', 'email' => 'praxis-am-hafen', 'phone' => '123456', 'city' => 'Hamburg'] as $feld => $klartext) {
        expect((string) $zeile[$feld])->not->toContain($klartext, "{$feld} liegt im Klartext.")
            ->and(Crypt::decryptString((string) $zeile[$feld]))->toContain($klartext);
    }
});

it('liest eine Demo-Anfrage ohne Mandanten', function (): void {
    // Sie gehoert keiner Praxis -- auch wenn gerade eine angemeldet ist.
    $anfrage = DemoRequest::factory()->create(['practice_name' => 'Praxis am Hafen']);
    alsMandant(organisation('Andere Praxis'));

    expect(DemoRequest::query()->whereKey($anfrage->getKey())->sole()->practice_name)->toBe('Praxis am Hafen');

    ohneMandant();

    expect(DemoRequest::query()->count())->toBe(1);
});

it('faengt eine automatisierte Anfrage im Honigtopf', function (): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular(['website' => 'https://spam.example']))->assertSessionHasErrors('website');

    expect(DemoRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('weist eine Anfrage ab, die schneller kommt als die Mindestzeit', function (): void {
    Notification::fake();

    $merkmal = app(Formularmerkmal::class)->erzeuge(CarbonImmutable::now()->subSecond());

    post(route('demoanfrage.senden'), demoFormular(['merkmal' => $merkmal]))->assertSessionHasErrors('merkmal');

    expect(DemoRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('weist ein fehlendes, gefaelschtes oder abgelaufenes Merkmal ab', function (string $merkmal): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular(['merkmal' => $merkmal]))->assertSessionHasErrors('merkmal');

    expect(DemoRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    // Pest ruft die Closures erst im Test auf -- nach travelTo().
    'fehlt' => fn (): string => '',
    'gefaelscht' => fn (): string => base64_encode((string) CarbonImmutable::now()->subMinute()->getTimestamp()),
    'abgelaufen' => fn (): string => app(Formularmerkmal::class)->erzeuge(CarbonImmutable::now()->subHours(25)),
    'aus der Zukunft' => fn (): string => app(Formularmerkmal::class)->erzeuge(CarbonImmutable::now()->addHour()),
    'fremd verschluesselt' => fn (): string => Crypt::encryptString('kein Zeitstempel'),
]);

it('gibt der Startseite ein Merkmal mit, das nach der Mindestzeit gilt', function (): void {
    Notification::fake();

    $merkmal = '';

    get('/')->assertInertia(function ($seite) use (&$merkmal) {
        $merkmal = (string) $seite->toArray()['props']['demoformular']['merkmal'];

        return $seite;
    });

    travelTo(CarbonImmutable::now()->addSeconds(10));

    post(route('demoanfrage.senden'), demoFormular(['merkmal' => $merkmal]))->assertSessionHasNoErrors();

    expect(DemoRequest::query()->count())->toBe(1);
});

it('drosselt die sechste Anfrage einer Minute', function (): void {
    Notification::fake();

    foreach (range(1, 5) as $versuch) {
        post(route('demoanfrage.senden'), demoFormular(['email' => "person{$versuch}@praxis.test"]))->assertSessionHasNoErrors();
    }

    post(route('demoanfrage.senden'), demoFormular())->assertStatus(429);

    expect(DemoRequest::query()->count())->toBe(5);
});

it('benachrichtigt den Vertrieb an die konfigurierte Adresse ueber den Plattformversand', function (): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular())->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(Demoanfrage::class, 1);
    Notification::assertSentOnDemand(Demoanfrage::class, function (Demoanfrage $nachricht, array $kanaele, AnonymousNotifiable $an): bool {
        return $an->routes['mail'] === 'vertrieb@example.test'
            && $kanaele === [PlattformMailkanal::class];
    });
});

it('nennt in der Mail keine Angabe der Anfrage und im Betreff keinen Namen', function (): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular())->assertSessionHasNoErrors();

    $nachricht = null;

    Notification::assertSentOnDemand(Demoanfrage::class, function (Demoanfrage $gesandt) use (&$nachricht): bool {
        $nachricht = $gesandt;

        return true;
    });

    assert($nachricht instanceof Demoanfrage);

    $mail = $nachricht->toMail((new AnonymousNotifiable)->route('mail', 'vertrieb@example.test'));
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Neue Demo-Anfrage');

    foreach (['Jana', 'Berger', 'Praxis am Hafen', 'praxis-am-hafen', '123456', 'Hamburg'] as $angabe) {
        expect($html)->not->toContain($angabe)
            ->and((string) $mail->subject)->not->toContain($angabe);
    }

    // Wo sie steht, sagt die Mail -- und wann sie kam, in Ortszeit.
    expect($html)->toContain(route('backoffice.demoanfragen'))
        ->and($html)->toContain('12.01.2027, 09:00 Uhr');
});

it('legt die Anfrage an, bevor die Mail in die Schlange geht', function (): void {
    Notification::fake();

    post(route('demoanfrage.senden'), demoFormular())->assertSessionHasNoErrors();

    $anfrage = DemoRequest::query()->sole();

    Notification::assertSentOnDemand(Demoanfrage::class, fn (Demoanfrage $nachricht): bool => $nachricht->anfrage === (string) $anfrage->uuid);
});
