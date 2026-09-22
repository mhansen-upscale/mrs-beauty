<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\RohereignisVerarbeiten;
use App\Kanaele\Eingangsverarbeitung;
use App\Kanaele\Kanaleingaenge;
use App\Kanaele\Konversationen;
use App\Kanaele\MetaSignatur;
use App\Models\ChannelIdentity;
use App\Models\ChannelRawEvent;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\Kanalaufbau;
use Tests\Feature\Kanaele\Testkanal;

/*
|--------------------------------------------------------------------------
| WP-19, Abnahmekriterien 1 bis 10 und 26 -- Empfang und Deduplizierung
|--------------------------------------------------------------------------
|
| Der Ablauf aus docs/integrationen/meta.md: Signatur prüfen, sofort
| quittieren, asynchron verarbeiten, über die externe ID deduplizieren.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    config(['mrs.meta.app_secret' => 'app-geheimnis', 'mrs.meta.webhook_verify_token' => 'bestaetigung']);
});

/**
 * @param  array<string, mixed>  $nutzlast
 * @return array<string, string>
 */
function signiert(array $nutzlast): array
{
    return ['X-Hub-Signature-256' => MetaSignatur::bilde((string) json_encode($nutzlast), 'app-geheimnis')];
}

it('beantwortet die Bestaetigungsanfrage mit der Aufgabe', function (): void {
    get(route('meta.webhook.verify', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'bestaetigung',
        'hub_challenge' => '1234567890',
    ]))->assertOk()->assertSee('1234567890');
});

it('beantwortet eine falsche Bestaetigung nicht', function (): void {
    get(route('meta.webhook.verify', [
        'hub_verify_token' => 'geraten',
        'hub_challenge' => '1234567890',
    ]))->assertForbidden()->assertDontSee('1234567890');
});

it('verwirft eine falsche Signatur, ohne etwas zu speichern', function (): void {
    $aufbau = new Kanalaufbau;
    ohneMandant();
    Queue::fake();

    $nutzlast = Testkanal::zustellung($aufbau->verbindung->external_id, 'psid-1', 'mid-1');

    postJson(route('meta.webhook'), $nutzlast, ['X-Hub-Signature-256' => 'sha256=falsch'])
        ->assertForbidden();

    alsMandant($aufbau->organisation);

    expect(ChannelRawEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('verwirft eine Zustellung ohne Signaturkopf', function (): void {
    $aufbau = new Kanalaufbau;
    ohneMandant();

    postJson(route('meta.webhook'), Testkanal::zustellung($aufbau->verbindung->external_id, 'psid-1', 'mid-1'))
        ->assertForbidden();

    alsMandant($aufbau->organisation);

    expect(ChannelRawEvent::query()->count())->toBe(0);
});

it('quittiert vor der Verarbeitung', function (): void {
    $aufbau = new Kanalaufbau;
    ohneMandant();
    Queue::fake();

    $nutzlast = Testkanal::zustellung($aufbau->verbindung->external_id, 'psid-1', 'mid-1');

    postJson(route('meta.webhook'), $nutzlast, signiert($nutzlast))->assertOk();

    alsMandant($aufbau->organisation);

    // Gespeichert ja, verarbeitet nein -- die Arbeit laeuft auf der Queue.
    expect(ChannelRawEvent::query()->count())->toBe(1)
        ->and(ChannelRawEvent::query()->firstOrFail()->processed_at)->toBeNull()
        ->and(Message::query()->count())->toBe(0);

    Queue::assertPushed(RohereignisVerarbeiten::class, 1);
});

it('verarbeitet auf der Queue realtime', function (): void {
    $aufbau = new Kanalaufbau;
    ohneMandant();
    Queue::fake();

    $nutzlast = Testkanal::zustellung($aufbau->verbindung->external_id, 'psid-1', 'mid-1');
    postJson(route('meta.webhook'), $nutzlast, signiert($nutzlast));

    Queue::assertPushed(RohereignisVerarbeiten::class, function (RohereignisVerarbeiten $auftrag): bool {
        return $auftrag->queue === 'realtime';
    });
});

it('erzeugt aus derselben Zustellung ein Rohereignis, nicht zwei', function (): void {
    // "Meta liefert Webhooks doppelt. Das ist kein Randfall, sondern
    // Normalbetrieb."
    $aufbau = new Kanalaufbau;
    ohneMandant();
    Queue::fake();

    $nutzlast = Testkanal::zustellung($aufbau->verbindung->external_id, 'psid-1', 'mid-1');

    postJson(route('meta.webhook'), $nutzlast, signiert($nutzlast))->assertOk();
    postJson(route('meta.webhook'), $nutzlast, signiert($nutzlast))->assertOk();

    alsMandant($aufbau->organisation);

    expect(ChannelRawEvent::query()->count())->toBe(1);
    Queue::assertPushed(RohereignisVerarbeiten::class, 1);
});

it('erzeugt aus derselben Nachricht eine Nachricht, nicht zwei', function (): void {
    // Dieselbe Nachricht kann in zwei verschiedenen Zustellungen stecken.
    $aufbau = new Kanalaufbau;

    $ersteZustellung = Testkanal::zustellung($aufbau->verbindung->external_id, 'psid-1', 'mid-1');
    $zweiteZustellung = Testkanal::zustellung($aufbau->verbindung->external_id, 'psid-1', 'mid-1', 'Hallo nochmal');

    ohneMandant();

    postJson(route('meta.webhook'), $ersteZustellung, signiert($ersteZustellung))->assertOk();
    postJson(route('meta.webhook'), $zweiteZustellung, signiert($zweiteZustellung))->assertOk();

    alsMandant($aufbau->organisation);

    // Zwei verschiedene Zustellungen, dieselbe Nachricht.
    expect(ChannelRawEvent::query()->count())->toBe(2)
        ->and(Message::query()->count())->toBe(1);
});

it('verarbeitet aus einer Zustellung die neue Nachricht neben der bekannten', function (): void {
    $aufbau = new Kanalaufbau;
    $seite = $aufbau->verbindung->external_id;

    $erste = Testkanal::zustellung($seite, 'psid-1', 'mid-1');

    $beide = $erste;
    $beide['entry'][0]['messaging'][] = [
        'sender' => ['id' => 'psid-1'],
        'recipient' => ['id' => $seite],
        'timestamp' => 1_800_000_000_000,
        'message' => ['mid' => 'mid-2', 'text' => 'Und noch etwas'],
    ];

    ohneMandant();

    postJson(route('meta.webhook'), $erste, signiert($erste))->assertOk();
    postJson(route('meta.webhook'), $beide, signiert($beide))->assertOk();

    alsMandant($aufbau->organisation);

    expect(Message::query()->count())->toBe(2);
});

it('schliesst einen zweiten Eintrag derselben Kennung auf Datenbankebene aus', function (): void {
    $aufbau = new Kanalaufbau;

    $identitaet = ChannelIdentity::create([
        'channel' => $aufbau->verbindung->channel,
        'external_id' => 'psid-1',
    ]);

    $konversation = app(Konversationen::class)->fuer($identitaet);

    app(Konversationen::class)->nimmAuf($konversation, 'mid-1', 'Erste');

    $zweite = new Message;
    $zweite->conversation_id = $konversation->getKey();
    $zweite->channel = $konversation->channel;
    $zweite->direction = MessageDirection::Inbound;
    $zweite->status = MessageStatus::Delivered;
    $zweite->external_id = 'mid-1';

    expect(fn () => $zweite->save())->toThrow(QueryException::class);
});

it('findet den Mandanten ueber die Gegenstelle, nicht ueber die Anfrage', function (): void {
    $erste = new Kanalaufbau(seite: 'seite-eins');
    $zweite = new Kanalaufbau(organisation: organisation('Zweite Praxis'), seite: 'seite-zwei');

    ohneMandant();

    $nutzlast = Testkanal::zustellung('seite-zwei', 'psid-9', 'mid-9');
    postJson(route('meta.webhook'), $nutzlast, signiert($nutzlast))->assertOk();

    alsMandant($zweite->organisation);
    expect(ChannelRawEvent::query()->count())->toBe(1);

    alsMandant($erste->organisation);
    expect(ChannelRawEvent::query()->count())->toBe(0);
});

it('laesst eine Zustellung einer unbekannten Gegenstelle liegen', function (): void {
    $aufbau = new Kanalaufbau;
    ohneMandant();

    $nutzlast = Testkanal::zustellung('fremde-seite', 'psid-1', 'mid-1');

    postJson(route('meta.webhook'), $nutzlast, signiert($nutzlast))->assertOk();

    alsMandant($aufbau->organisation);

    expect(ChannelRawEvent::query()->count())->toBe(0);
});

it('laesst ein Rohereignis ohne Leser zum erneuten Einspielen liegen', function (): void {
    $aufbau = new Kanalaufbau;

    // **Telefon, und das bleibt so.** Vor WP-20 war jeder Kanal ohne Leser;
    // dann bekam WhatsApp einen, dann E-Mail -- und dieser Testfall wanderte
    // zweimal weiter und wurde dabei jedes Mal gruen, ohne noch etwas zu
    // pruefen. Telefon hat dauerhaft keinen Leser (Entscheidung P3: kein
    // Telefon, kein Voice-Agent) und ist deshalb der ehrliche
    // Stellvertreter, nicht der naechste Kanal in der Reihe.
    expect(app(Kanaleingaenge::class)->fuer(ChannelType::Phone))->toBeNull();

    $ereignis = new ChannelRawEvent;
    $ereignis->channel = ChannelType::Phone;
    $ereignis->external_id = 'ohne-leser';
    $ereignis->payload = '{}';
    $ereignis->save();

    app(Eingangsverarbeitung::class)->verarbeite($ereignis);

    $frisch = $ereignis->fresh();

    expect($frisch?->processed_at)->toBeNull()
        ->and($frisch?->failure)->toBe('no_reader')
        ->and($aufbau->verbindung->exists)->toBeTrue();
});

it('legt Kanalidentitaet und Konversation an, wenn es sie nicht gibt', function (): void {
    $aufbau = new Kanalaufbau;
    ohneMandant();

    $nutzlast = Testkanal::zustellung($aufbau->verbindung->external_id, 'psid-neu', 'mid-1');
    postJson(route('meta.webhook'), $nutzlast, signiert($nutzlast))->assertOk();

    alsMandant($aufbau->organisation);

    $identitaet = ChannelIdentity::query()->firstOrFail();

    expect($identitaet->external_id)->toBe('psid-neu')
        // Ohne Kontakt: die erste Nachricht kommt an, bevor jemand weiss,
        // wer da schreibt (Entscheidung D5).
        ->and($identitaet->contact_id)->toBeNull()
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Message::query()->firstOrFail()->body)->toBe('Hallo');
});
