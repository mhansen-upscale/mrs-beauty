<?php

declare(strict_types=1);

use App\Datenschutz\Anhangspeicher;
use App\Enums\AttachmentContext;
use App\Enums\ChannelType;
use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Kanaele\Eingangsverarbeitung;
use App\Kanaele\Konversationen;
use App\Kanaele\Rohereignisse;
use App\Models\Attachment;
use App\Models\ChannelIdentity;
use App\Models\ChannelRawEvent;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\WhatsAppAufbau;

/*
|--------------------------------------------------------------------------
| WP-20a, Abnahmekriterien 1 bis 16 -- Empfang und Rueckmeldungen
|--------------------------------------------------------------------------
|
| Der Kanal, fuer den WP-19 gebaut wurde. Geprueft wird gegen die Nutzlast der
| Cloud API, nicht gegen Meta: die Berechtigungen aus WP-00 entscheiden
| darueber, ob produktiv gesprochen werden darf, nicht darueber, ob die
| Umsetzung stimmt.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Spielt eine Zustellung durch die Strecke aus WP-19.
 *
 * @param  array<string, mixed>  $eintrag
 */
function verarbeite(WhatsAppAufbau $aufbau, array $eintrag): int
{
    $nutzlast = (string) json_encode($eintrag);

    $ereignis = app(Rohereignisse::class)->nimmAuf($aufbau->verbindung, hash('sha256', $nutzlast), $nutzlast);

    expect($ereignis)->toBeInstanceOf(ChannelRawEvent::class);

    return app(Eingangsverarbeitung::class)->verarbeite($ereignis instanceof ChannelRawEvent ? $ereignis : throw new RuntimeException);
}

/* Empfang ------------------------------------------------------------------ */

it('nimmt eine Textnachricht mit Absender, Inhalt und Zeitpunkt auf', function (): void {
    $aufbau = new WhatsAppAufbau;

    $neu = verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.1',
        'timestamp' => '1800000000',
        'type' => 'text',
        'text' => ['body' => 'Guten Tag, ist morgen etwas frei?'],
    ]));

    $nachricht = Message::query()->firstOrFail();
    $identitaet = ChannelIdentity::query()->firstOrFail();

    expect($neu)->toBe(1)
        ->and($nachricht->channel)->toBe(ChannelType::WhatsApp)
        ->and($nachricht->direction)->toBe(MessageDirection::Inbound)
        ->and($nachricht->external_id)->toBe('wamid.1')
        ->and($nachricht->body)->toBe('Guten Tag, ist morgen etwas frei?')
        ->and($identitaet->external_id)->toBe('4915112345678');
});

it('nimmt den Anzeigenamen aus dem Profil, nicht aus dem Text', function (): void {
    // Regel 5: was jemand schreibt, ist kein Name. Der Anzeigename kommt aus
    // contacts[].profile.name und sonst nirgendwoher.
    $aufbau = new WhatsAppAufbau;

    verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.1',
        'timestamp' => '1800000000',
        'type' => 'text',
        'text' => ['body' => 'Mein Name ist Dr. Sauer'],
    ]));

    expect(ChannelIdentity::query()->firstOrFail()->display_name)->toBe('Annika Müller');
});

it('liest den Zeitstempel als Sekunden, nicht als Millisekunden', function (): void {
    // Messenger benutzt denselben Feldnamen fuer Millisekunden. Wer das
    // verwechselt, datiert eine Nachricht von heute auf das Jahr 57000.
    $aufbau = new WhatsAppAufbau;

    verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.1',
        'timestamp' => '1800000000',
        'type' => 'text',
        'text' => ['body' => 'Hallo'],
    ]));

    expect(Message::query()->firstOrFail()->delivered_at?->toIso8601String())
        ->toBe(CarbonImmutable::createFromTimestamp(1800000000, 'UTC')->toIso8601String());
});

it('nimmt ein Bild mit Medientyp und Bildunterschrift auf', function (): void {
    $aufbau = new WhatsAppAufbau;

    // Die Datei selbst holt ein eigener Auftrag -- hier gibt Meta sie nicht
    // heraus, und die Nachricht steht trotzdem.
    Http::fake(['*' => Http::response(['error' => ['code' => 100]], 404)]);

    verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.1',
        'timestamp' => '1800000000',
        'type' => 'image',
        'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg', 'caption' => 'So sieht es aus'],
    ]));

    $nachricht = Message::query()->firstOrFail();

    expect($nachricht->media_type)->toBe('image/jpeg')
        ->and($nachricht->body)->toBe('So sieht es aus');
});

/* Medien (offen seit WP-20a) ------------------------------------------------ */

/** Metas zwei Schritte: Auskunft zur Kennung, dann die Datei. */
function metaMedien(int $groesse = 8, string $adresse = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1'): void
{
    Http::fake([
        'graph.facebook.com/*/media-1' => Http::response([
            'id' => 'media-1',
            'url' => $adresse,
            'mime_type' => 'image/jpeg',
            'file_size' => $groesse,
        ]),
        'lookaside.fbsbx.com/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
        '*' => Http::response('', 500),
    ]);
}

/** @return array<string, mixed> */
function bildnachricht(): array
{
    return WhatsAppAufbau::zustellung([
        'id' => 'wamid.bild',
        'timestamp' => '1800000000',
        'type' => 'image',
        'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg'],
    ]);
}

it('holt ein Bild ueber den Media-Endpunkt und legt es als Chat-Anhang ab', function (): void {
    Storage::fake('local');
    $aufbau = new WhatsAppAufbau;
    metaMedien();

    verarbeite($aufbau, bildnachricht());

    $anhang = Attachment::query()->firstOrFail();

    // Ueber den Anhangspeicher: Chat-Kontext, Pflicht-Ablaufdatum (C6), und
    // angezeigt wird erst nach der Virenpruefung.
    expect($anhang->context)->toBe(AttachmentContext::Chat)
        ->and($anhang->attachable_type)->toBe(Message::class)
        ->and($anhang->expires_at)->not->toBeNull()
        ->and(app(Anhangspeicher::class)->rohinhalt($anhang))->toBe('JPEGDATA');

    // Beide Schritte mit dem Token der Verbindung.
    Http::assertSent(fn (HttpRequest $anfrage): bool => str_contains($anfrage->url(), 'lookaside.fbsbx.com')
        && $anfrage->hasHeader('Authorization', 'Bearer systembenutzer-token'));
});

it('schickt das Token nicht an eine fremde Adresse', function (): void {
    Storage::fake('local');
    $aufbau = new WhatsAppAufbau;

    // Die Adresse der Datei kommt aus einer Antwort. Wer sie faelschen
    // koennte, bekaeme sonst das Zugangstoken der Praxis.
    metaMedien(adresse: 'https://boese.example/datei');

    verarbeite($aufbau, bildnachricht());

    expect(Attachment::query()->count())->toBe(0);
    Http::assertNotSent(fn (HttpRequest $anfrage): bool => str_contains($anfrage->url(), 'boese.example'));
});

it('holt keine Datei ueber der Grenze', function (): void {
    Storage::fake('local');
    $aufbau = new WhatsAppAufbau;
    metaMedien(groesse: (int) config('mrs.channels.whatsapp.max_media_bytes') + 1);

    verarbeite($aufbau, bildnachricht());

    expect(Attachment::query()->count())->toBe(0)
        ->and(Message::query()->firstOrFail()->media_type)->toBe('image/jpeg');
    Http::assertNotSent(fn (HttpRequest $anfrage): bool => str_contains($anfrage->url(), 'lookaside'));
});

it('zeigt eine Reaktion mit ihrem Emoji statt als leere Nachricht', function (): void {
    $aufbau = new WhatsAppAufbau;

    verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.2',
        'timestamp' => '1800000000',
        'type' => 'reaction',
        'reaction' => ['message_id' => 'wamid.1', 'emoji' => '👍'],
    ]));

    $nachricht = Message::query()->firstOrFail();

    expect($nachricht->body)->toBe('👍')
        ->and($nachricht->media_type)->toBe('reaction');
});

it('zeigt die Antwort auf eine Schaltflaeche mit ihrer Beschriftung', function (): void {
    $aufbau = new WhatsAppAufbau;

    verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.3',
        'timestamp' => '1800000000',
        'type' => 'interactive',
        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'ja', 'title' => 'Termin bestätigen']],
    ]));

    expect(Message::query()->firstOrFail()->body)->toBe('Termin bestätigen');
});

it('nimmt einen Standort ohne Koordinaten auf', function (): void {
    // Regel 3: wo sich jemand gerade aufhaelt, braucht eine
    // Terminvereinbarung nicht. Was nicht gebraucht wird, wird nicht
    // gespeichert.
    $aufbau = new WhatsAppAufbau;

    verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.4',
        'timestamp' => '1800000000',
        'type' => 'location',
        'location' => ['latitude' => 52.52, 'longitude' => 13.405, 'name' => 'Zuhause'],
    ]));

    $nachricht = Message::query()->firstOrFail();

    expect($nachricht->media_type)->toBe('location')
        ->and($nachricht->body)->toBeNull();

    $roh = (string) json_encode($nachricht->only(['body', 'media_type']));

    expect($roh)->not->toContain('52.52');
    expect($roh)->not->toContain('13.405');
});

it('macht aus einem Systemereignis keine Nachricht', function (): void {
    $aufbau = new WhatsAppAufbau;

    $neu = verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.5',
        'timestamp' => '1800000000',
        'type' => 'system',
        'system' => ['body' => 'Nutzer A hat die Rufnummer gewechselt', 'type' => 'user_changed_number'],
    ]));

    expect($neu)->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('erzeugt aus zwei Zustellungen derselben Nachricht eine', function (): void {
    // Meta liefert doppelt, im Normalbetrieb.
    $aufbau = new WhatsAppAufbau;

    $nachricht = [
        'id' => 'wamid.1',
        'timestamp' => '1800000000',
        'type' => 'text',
        'text' => ['body' => 'Hallo'],
    ];

    verarbeite($aufbau, WhatsAppAufbau::zustellung($nachricht));

    // Eine zweite Zustellung mit anderem Wortlaut drumherum -- damit der
    // Rohereignis-Hash abweicht und wirklich der Unique-Index auf messages
    // entscheidet und nicht die Deduplizierung eine Ebene darueber.
    $zweite = WhatsAppAufbau::zustellung($nachricht);
    $zweite['changes'][0]['value']['metadata']['display_phone_number'] = '4930999999';

    $neu = verarbeite($aufbau, $zweite);

    expect($neu)->toBe(0)
        ->and(Message::query()->count())->toBe(1);
});

it('oeffnet mit einer eingehenden Nachricht das Service-Fenster', function (): void {
    $aufbau = new WhatsAppAufbau;

    verarbeite($aufbau, WhatsAppAufbau::zustellung([
        'id' => 'wamid.1',
        'timestamp' => '1800000000',
        'type' => 'text',
        'text' => ['body' => 'Hallo'],
    ]));

    expect(Conversation::query()->firstOrFail()->fensterOffen())->toBeTrue();
});

/* Rueckmeldungen ----------------------------------------------------------- */

/** Eine gesendete Nachricht, auf die sich eine Rueckmeldung beziehen kann. */
function gesendeteNachricht(string $kennung = 'wamid.out'): Message
{
    $identitaet = ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
    ]);

    $konversation = app(Konversationen::class)->fuer($identitaet);

    $nachricht = new Message;
    $nachricht->conversation_id = $konversation->getKey();
    $nachricht->channel = ChannelType::WhatsApp;
    $nachricht->direction = MessageDirection::Outbound;
    $nachricht->status = MessageStatus::Sent;
    $nachricht->external_id = $kennung;
    $nachricht->body = 'Guten Tag';
    $nachricht->save();

    return $nachricht;
}

it('setzt mit "delivered" Zustand und Zeitpunkt', function (): void {
    $aufbau = new WhatsAppAufbau;
    $nachricht = gesendeteNachricht();

    verarbeite($aufbau, WhatsAppAufbau::statusmeldung('wamid.out', 'delivered'));

    $frisch = $nachricht->fresh();

    expect($frisch?->status)->toBe(MessageStatus::Delivered)
        ->and($frisch?->delivered_at?->toIso8601String())
        ->toBe(CarbonImmutable::createFromTimestamp(1800000000, 'UTC')->toIso8601String());
});

it('setzt "read" nach "delivered"', function (): void {
    $aufbau = new WhatsAppAufbau;
    $nachricht = gesendeteNachricht();

    verarbeite($aufbau, WhatsAppAufbau::statusmeldung('wamid.out', 'delivered'));

    $zweite = WhatsAppAufbau::statusmeldung('wamid.out', 'read');
    $zweite['changes'][0]['value']['statuses'][0]['timestamp'] = '1800000060';

    verarbeite($aufbau, $zweite);

    expect($nachricht->fresh()?->status)->toBe(MessageStatus::Read);
});

it('setzt mit einem verspaeteten "delivered" ein "read" nicht zurueck', function (): void {
    // Meta liefert doppelt und nicht in der Reihenfolge der Ereignisse. Eine
    // gelesene Nachricht wieder ungelesen zu machen, waere eine Falschaussage
    // in der Inbox.
    $aufbau = new WhatsAppAufbau;
    $nachricht = gesendeteNachricht();

    verarbeite($aufbau, WhatsAppAufbau::statusmeldung('wamid.out', 'read'));

    $spaet = WhatsAppAufbau::statusmeldung('wamid.out', 'delivered');
    $spaet['changes'][0]['value']['statuses'][0]['timestamp'] = '1799999940';

    verarbeite($aufbau, $spaet);

    expect($nachricht->fresh()?->status)->toBe(MessageStatus::Read);
});

it('uebernimmt die Kostenkategorie aus der Rueckmeldung', function (): void {
    // **Hier kommt sie her, nicht aus der Sendeantwort.**
    $aufbau = new WhatsAppAufbau;
    $nachricht = gesendeteNachricht();

    verarbeite($aufbau, WhatsAppAufbau::statusmeldung('wamid.out', 'delivered', [
        'pricing' => ['billable' => true, 'pricing_model' => 'CBP', 'category' => 'utility'],
    ]));

    expect($nachricht->fresh()?->cost_category)->toBe(MessageCostCategory::Utility);
});

it('laesst die Kategorie ohne Preisangabe unbekannt, nicht kostenlos', function (): void {
    // `none` waere die Schaetzung mit der Aussage "kostenlos" -- die
    // teuerste von allen.
    $aufbau = new WhatsAppAufbau;
    $nachricht = gesendeteNachricht();

    verarbeite($aufbau, WhatsAppAufbau::statusmeldung('wamid.out', 'delivered'));

    expect($nachricht->fresh()?->cost_category)->toBeNull();
});

it('haelt bei "failed" einen Kurzgrund fest, nicht die Meldung des Anbieters', function (): void {
    $aufbau = new WhatsAppAufbau;
    $nachricht = gesendeteNachricht();

    verarbeite($aufbau, WhatsAppAufbau::statusmeldung('wamid.out', 'failed', [
        'errors' => [['code' => 131047, 'title' => 'Re-engagement message', 'message' => 'Nachricht an Annika Müller']],
    ]));

    $frisch = $nachricht->fresh();

    expect($frisch?->status)->toBe(MessageStatus::Failed)
        ->and($frisch?->failure)->toBe('wa_131047')
        ->and($frisch?->failure)->not->toContain('Annika');
});

it('laeuft bei einer Rueckmeldung zu einer unbekannten Kennung ins Leere', function (): void {
    $aufbau = new WhatsAppAufbau;

    verarbeite($aufbau, WhatsAppAufbau::statusmeldung('wamid.fremd', 'delivered'));

    expect(Message::query()->count())->toBe(0);
});

it('oeffnet mit einer Rueckmeldung kein Service-Fenster', function (): void {
    // Eine Zustellbestaetigung fuer etwas, das wir selbst geschickt haben,
    // ist keine eingehende Nachricht.
    $aufbau = new WhatsAppAufbau;
    gesendeteNachricht();

    verarbeite($aufbau, WhatsAppAufbau::statusmeldung('wamid.out', 'delivered'));

    expect(Conversation::query()->firstOrFail()->fensterOffen())->toBeFalse();
});
