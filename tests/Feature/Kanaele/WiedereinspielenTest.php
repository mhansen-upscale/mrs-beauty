<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Kanaele\Eingangsverarbeitung;
use App\Kanaele\Kanaleingaenge;
use App\Kanaele\Kanaleingang;
use App\Kanaele\Rohereignisse;
use App\Models\ChannelConnection;
use App\Models\ChannelRawEvent;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\MailAufbau;
use Tests\Feature\Kanaele\WhatsAppAufbau;

/*
|--------------------------------------------------------------------------
| WP-19, Nachtrag 28.09.2026 -- Abnahmekriterien 27 bis 35
|--------------------------------------------------------------------------
|
| Ein Rohereignis, dessen Auftrag nie lief, steht nicht in failed_jobs --
| queue:retry erreicht es nicht. Nach 14 Tagen raeumt die Aufbewahrung es
| weg, und mit ihm die Nachricht. Dafuer gibt es den Befehl.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Ein Rohereignis, wie der Webhook es ablegt -- nur ohne den Auftrag danach.
 */
function liegenlassen(ChannelConnection $verbindung, string $kennung, string $nutzlast): ChannelRawEvent
{
    $ereignis = app(Rohereignisse::class)->nimmAuf($verbindung, $kennung, $nutzlast);

    return $ereignis instanceof ChannelRawEvent ? $ereignis : throw new RuntimeException('Rohereignis gab es schon');
}

function whatsappNutzlast(string $kennung = 'wamid.1'): string
{
    return (string) json_encode(WhatsAppAufbau::zustellung([
        'id' => $kennung,
        'timestamp' => '1800000000',
        'type' => 'text',
        'text' => ['body' => 'Guten Tag, ist morgen etwas frei?'],
    ]));
}

function nachDerWartezeit(): void
{
    travelTo(CarbonImmutable::now()->addMinutes((int) config('mrs.meta.raw_event_replay_after_minutes') + 1));
}

/**
 * Der Befehl, wie ihn jemand in der Konsole aufruft: ohne Mandanten.
 *
 * @param  array<string, mixed>  $optionen
 */
function einspielen(array $optionen = []): int
{
    ohneMandant();

    return Artisan::call('mrs:rohereignisse-einspielen', $optionen);
}

it('spielt ein liegengebliebenes Rohereignis ein', function (): void {
    $aufbau = new WhatsAppAufbau;
    $ereignis = liegenlassen($aufbau->verbindung, 'e-1', whatsappNutzlast());

    nachDerWartezeit();

    expect(einspielen())->toBe(0);

    alsMandant($aufbau->organisation);

    $frisch = $ereignis->fresh();

    expect(Message::query()->count())->toBe(1)
        ->and(Message::query()->firstOrFail()->external_id)->toBe('wamid.1')
        ->and($frisch?->processed_at)->not->toBeNull()
        ->and($frisch?->failure)->toBeNull();
});

it('veraendert mit --nur-zeigen nichts', function (): void {
    $aufbau = new WhatsAppAufbau;
    $ereignis = liegenlassen($aufbau->verbindung, 'e-1', whatsappNutzlast());

    nachDerWartezeit();

    expect(einspielen(['--nur-zeigen' => true]))->toBe(0);

    $ausgabe = Artisan::output();

    alsMandant($aufbau->organisation);

    expect($ereignis->fresh()?->processed_at)->toBeNull()
        ->and($ereignis->fresh()?->attempts)->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and($ausgabe)->toContain((string) $ereignis->uuid)
        ->and($ausgabe)->toContain('whatsapp');
});

it('laesst ein verarbeitetes Rohereignis in Ruhe', function (): void {
    $aufbau = new WhatsAppAufbau;
    $ereignis = liegenlassen($aufbau->verbindung, 'e-1', whatsappNutzlast());
    app(Eingangsverarbeitung::class)->verarbeite($ereignis);

    nachDerWartezeit();

    expect(einspielen())->toBe(0);

    alsMandant($aufbau->organisation);

    // Ein zweiter Durchlauf haette den Zaehler erhoeht.
    expect($ereignis->fresh()?->attempts)->toBe(1)
        ->and(Message::query()->count())->toBe(1);
});

it('laesst ein junges Rohereignis liegen, an dem vielleicht gerade ein Worker sitzt', function (): void {
    $aufbau = new WhatsAppAufbau;
    $ereignis = liegenlassen($aufbau->verbindung, 'e-1', whatsappNutzlast());

    travelTo(CarbonImmutable::now()->addMinutes(10));

    expect(einspielen())->toBe(0);

    alsMandant($aufbau->organisation);

    expect($ereignis->fresh()?->processed_at)->toBeNull()
        ->and(Message::query()->count())->toBe(0);
});

it('erzeugt eine Nachricht, die es schon gibt, kein zweites Mal', function (): void {
    // Der Auftrag lief bis zur Nachricht und brach vor dem Vermerk ab.
    $aufbau = new WhatsAppAufbau;
    $ereignis = liegenlassen($aufbau->verbindung, 'e-1', whatsappNutzlast());
    app(Eingangsverarbeitung::class)->verarbeite($ereignis);
    ChannelRawEvent::query()->whereKey($ereignis->getKey())->update(['processed_at' => null]);

    nachDerWartezeit();

    expect(einspielen())->toBe(0);

    alsMandant($aufbau->organisation);

    expect(Message::query()->count())->toBe(1)
        ->and($ereignis->fresh()?->processed_at)->not->toBeNull();
});

it('spielt jede Praxis in ihrem eigenen Mandanten ein, mit --organisation nur eine', function (): void {
    $aufbau = new WhatsAppAufbau;
    $erstes = liegenlassen($aufbau->verbindung, 'e-1', whatsappNutzlast('wamid.1'));

    $zweite = alsMandant(organisation('Zweite Praxis'));
    $verbindung = new ChannelConnection;
    $verbindung->channel = ChannelType::WhatsApp;
    $verbindung->status = ConnectionStatus::Active;
    $verbindung->external_id = 'waba-0815';
    $verbindung->sender_id = 'nummer-11';
    $verbindung->display_name = 'Zweite Praxis';
    $verbindung->access_token = 'anderes-token';
    $verbindung->save();
    $zweites = liegenlassen($verbindung, 'e-2', whatsappNutzlast('wamid.2'));

    nachDerWartezeit();

    expect(einspielen(['--organisation' => (string) $zweite->uuid]))->toBe(0);

    alsMandant($aufbau->organisation);
    expect($erstes->fresh()?->processed_at)->toBeNull()
        ->and(Message::query()->count())->toBe(0);

    alsMandant($zweite);
    expect($zweites->fresh()?->processed_at)->not->toBeNull()
        ->and(Message::query()->pluck('external_id')->all())->toBe(['wamid.2']);

    expect(einspielen())->toBe(0);

    alsMandant($aufbau->organisation);
    expect($erstes->fresh()?->processed_at)->not->toBeNull()
        ->and(Message::query()->pluck('external_id')->all())->toBe(['wamid.1']);

    alsMandant($zweite);
    expect(Message::query()->pluck('external_id')->all())->toBe(['wamid.2']);
});

it('beschraenkt sich mit --kanal auf einen Kanal', function (): void {
    $whatsapp = new WhatsAppAufbau;
    $mail = new MailAufbau($whatsapp->organisation);

    $nachricht = liegenlassen($whatsapp->verbindung, 'e-1', whatsappNutzlast());
    $brief = liegenlassen($mail->verbindung, 'abc-1@example.test', (string) json_encode(['raw' => MailAufbau::mail()]));

    nachDerWartezeit();

    expect(einspielen(['--kanal' => 'email']))->toBe(0);

    alsMandant($whatsapp->organisation);

    expect($brief->fresh()?->processed_at)->not->toBeNull()
        ->and($nachricht->fresh()?->processed_at)->toBeNull()
        ->and(Message::query()->pluck('channel')->all())->toBe([ChannelType::Email]);
});

it('vermerkt einen Fehlschlag mit Kurzgrund und macht mit dem naechsten weiter', function (): void {
    $aufbau = new WhatsAppAufbau;

    // Ein Leser, der scheitert -- und dessen Meldung Inhalt traegt, wie es
    // die eines Anbieters regelmaessig tut.
    app(Kanaleingaenge::class)->registriere(ChannelType::Messenger, new class implements Kanaleingang
    {
        public function lies(array $eintrag): array
        {
            throw new RuntimeException('Annika Müller schreibt: Guten Tag');
        }
    });

    $kaputt = new ChannelRawEvent;
    $kaputt->channel = ChannelType::Messenger;
    $kaputt->external_id = 'kaputt';
    $kaputt->payload = '{}';
    $kaputt->save();

    $heil = liegenlassen($aufbau->verbindung, 'e-1', whatsappNutzlast());

    nachDerWartezeit();

    expect(einspielen())->toBe(1);

    $ausgabe = Artisan::output();

    alsMandant($aufbau->organisation);

    expect($kaputt->fresh()?->processed_at)->toBeNull()
        ->and($kaputt->fresh()?->failure)->toBe('replay_failed')
        ->and($kaputt->fresh()?->attempts)->toBe(1)
        ->and($heil->fresh()?->processed_at)->not->toBeNull()
        ->and(Message::query()->count())->toBe(1)
        ->and($ausgabe)->toContain('RuntimeException')
        ->and($ausgabe)->not->toContain('Annika');
});

it('zaehlt ein Rohereignis ohne Leser als nicht eingespielt', function (): void {
    // Telefon hat dauerhaft keinen Leser (Entscheidung P3), siehe WebhookTest.
    $aufbau = new WhatsAppAufbau;

    $ereignis = new ChannelRawEvent;
    $ereignis->channel = ChannelType::Phone;
    $ereignis->external_id = 'ohne-leser';
    $ereignis->payload = '{}';
    $ereignis->save();

    nachDerWartezeit();

    expect(einspielen())->toBe(1);

    alsMandant($aufbau->organisation);

    expect($ereignis->fresh()?->processed_at)->toBeNull()
        ->and($ereignis->fresh()?->failure)->toBe('no_reader');
});

it('gibt weder Nutzlast noch Nachrichtentext aus', function (): void {
    $aufbau = new WhatsAppAufbau;
    liegenlassen($aufbau->verbindung, 'e-1', whatsappNutzlast());

    nachDerWartezeit();

    einspielen(['--nur-zeigen' => true]);
    $vorschau = Artisan::output();

    einspielen();
    $lauf = Artisan::output();

    foreach ([$vorschau, $lauf] as $ausgabe) {
        expect($ausgabe)->not->toContain('Guten Tag')
            ->and($ausgabe)->not->toContain('4915112345678')
            ->and($ausgabe)->not->toContain('Annika');
    }
});

it('lehnt einen unbekannten Kanal und eine ungueltige Organisation ab', function (): void {
    expect(einspielen(['--kanal' => 'fax']))->toBe(1)
        ->and(Artisan::output())->toContain('Unbekannter Kanal')
        ->and(einspielen(['--organisation' => 'keine-uuid']))->toBe(1)
        ->and(Artisan::output())->toContain('Keine gueltige UUID');
});
