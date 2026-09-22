<?php

declare(strict_types=1);

use App\Datenschutz\Einwilligungen;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Enums\ConsentType;
use App\Enums\MessageCostCategory;
use App\Enums\MessageStatus;
use App\Enums\TemplateStatus;
use App\Kanaele\Konversationen;
use App\Kanaele\Nachrichtenversand;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\WhatsAppAufbau;

/*
|--------------------------------------------------------------------------
| WP-20a, Abnahmekriterien 17 bis 28 -- Versand und Templates
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** Die Antwort der Cloud API auf einen Versand: eine Kennung, sonst nichts. */
function antwortetMitKennung(string $kennung = 'wamid.gesendet'): void
{
    Http::fake(['graph.facebook.com/*' => Http::response([
        'messaging_product' => 'whatsapp',
        'contacts' => [['input' => '4915112345678', 'wa_id' => '4915112345678']],
        'messages' => [['id' => $kennung, 'message_status' => 'accepted']],
    ])]);
}

/**
 * Eine Konversation mit offenem oder geschlossenem Fenster.
 */
function whatsappGespraech(bool $imFenster = true, string $kennung = '4915112345678'): Conversation
{
    $identitaet = ChannelIdentity::query()->mitKennung(ChannelType::WhatsApp, $kennung)->first()
        ?? ChannelIdentity::create([
            'channel' => ChannelType::WhatsApp,
            'external_id' => $kennung,
        ]);

    $konversation = app(Konversationen::class)->fuer($identitaet);

    if ($imFenster) {
        app(Konversationen::class)->nimmAuf($konversation, 'wamid.rein', 'Hallo');
    }

    return $konversation->fresh() ?? $konversation;
}

function genehmigtesTemplate(string $name = 'terminerinnerung'): WhatsAppTemplate
{
    $template = new WhatsAppTemplate;
    $template->name = $name;
    $template->language = 'de';
    $template->category = MessageCostCategory::Utility;
    $template->status = TemplateStatus::Approved;
    $template->body = 'Guten Tag {{1}}, Ihr Termin am {{2}} steht.';
    $template->variables = 2;
    $template->save();

    return $template;
}

/* Versand ------------------------------------------------------------------ */

it('schickt im Fenster einen Text an die Rufnummern-ID', function (): void {
    // **Zwei Kennungen, eine Verbindung**: die Zustellung kommt unter der
    // WABA-Kennung an, gesendet wird unter der Rufnummer.
    new WhatsAppAufbau;
    antwortetMitKennung();

    $konversation = whatsappGespraech();

    app(Nachrichtenversand::class)->stelleEin($konversation, 'Guten Tag, ja, morgen um 10 Uhr.');

    Http::assertSent(function (Request $anfrage): bool {
        return str_contains($anfrage->url(), '/'.WhatsAppAufbau::RUFNUMMER.'/messages')
            && ! str_contains($anfrage->url(), WhatsAppAufbau::WABA)
            && $anfrage['type'] === 'text'
            && $anfrage['to'] === '4915112345678'
            && $anfrage['text']['body'] === 'Guten Tag, ja, morgen um 10 Uhr.'
            && $anfrage['text']['preview_url'] === false;
    });

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->status)
        ->toBe(MessageStatus::Sent);
});

it('haelt die Kennung der Antwort fest', function (): void {
    new WhatsAppAufbau;
    antwortetMitKennung('wamid.abc');

    app(Nachrichtenversand::class)->stelleEin(whatsappGespraech(), 'Antwort');

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->external_id)
        ->toBe('wamid.abc');
});

it('laesst die Kostenkategorie einer frisch gesendeten Nachricht offen', function (): void {
    // Die Sendeantwort kennt die Kosten nicht -- `pricing` kommt mit der
    // Statusrueckmeldung. `none` waere hier die Schaetzung "kostenlos".
    new WhatsAppAufbau;
    antwortetMitKennung();

    app(Nachrichtenversand::class)->stelleEin(whatsappGespraech(), 'Antwort');

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->cost_category)->toBeNull();
});

it('lehnt ausserhalb des Fensters einen Text ab, ohne zu wiederholen', function (): void {
    // Eine Nachricht, die draussen als "gesendet" gilt und nie ankommt,
    // faellt erst auf, wenn niemand antwortet.
    new WhatsAppAufbau;
    antwortetMitKennung();

    app(Nachrichtenversand::class)->stelleEin(whatsappGespraech(imFenster: false), 'Antwort');

    $nachricht = Message::query()->where('direction', 'outbound')->firstOrFail();

    expect($nachricht->status)->toBe(MessageStatus::Failed)
        ->and($nachricht->failure)->toBe('window_closed');

    Http::assertNothingSent();
});

it('schickt ausserhalb des Fensters ein genehmigtes Template', function (): void {
    new WhatsAppAufbau;
    antwortetMitKennung();

    $konversation = whatsappGespraech(imFenster: false);

    app(Einwilligungen::class)->erteile(
        $konversation->channelIdentity,
        ConsentType::WhatsApp,
        'buchung-2027-01',
        'Ich möchte Nachrichten über WhatsApp erhalten.',
    );

    app(Nachrichtenversand::class)->stelleTemplateEin(
        $konversation,
        genehmigtesTemplate(),
        ['Annika Müller', '13.01. um 10:00 Uhr'],
    );

    Http::assertSent(function (Request $anfrage): bool {
        return $anfrage['type'] === 'template'
            && $anfrage['template']['name'] === 'terminerinnerung'
            && $anfrage['template']['language']['code'] === 'de'
            && $anfrage['template']['components'][0]['parameters'][1]['text'] === '13.01. um 10:00 Uhr';
    });

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->status)
        ->toBe(MessageStatus::Sent);
});

it('lehnt ein nicht genehmigtes Template ab, ohne den Anbieter zu fragen', function (): void {
    // Die Ablehnung kostet sonst eine Anfrage, die auf das Rate Limit geht.
    new WhatsAppAufbau;
    antwortetMitKennung();

    $konversation = whatsappGespraech(imFenster: false);
    $template = genehmigtesTemplate();
    $template->status = TemplateStatus::Rejected;
    $template->save();

    app(Nachrichtenversand::class)->stelleTemplateEin($konversation, $template, ['Annika', 'morgen']);

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->failure)
        ->toBe('template_not_approved');

    Http::assertNothingSent();
});

it('sendet ausserhalb des Fensters nichts ohne Einwilligung', function (): void {
    new WhatsAppAufbau;
    antwortetMitKennung();

    app(Nachrichtenversand::class)->stelleTemplateEin(
        whatsappGespraech(imFenster: false),
        genehmigtesTemplate(),
        ['Annika', 'morgen'],
    );

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->failure)->toBe('no_optin');

    Http::assertNothingSent();
});

it('braucht im Fenster keine Einwilligung', function (): void {
    // Wer uns schreibt, hat sich damit gemeldet. Ein weiterer Nachweis fuer
    // die Antwort waere eine Huerde ohne Schutzwirkung.
    new WhatsAppAufbau;
    antwortetMitKennung();

    app(Nachrichtenversand::class)->stelleEin(whatsappGespraech(), 'Gern, morgen um 10 Uhr.');

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->status)
        ->toBe(MessageStatus::Sent);
});

it('setzt bei ungueltigem Token die Verbindung auf unterbrochen', function (): void {
    $aufbau = new WhatsAppAufbau;

    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['code' => 190, 'message' => 'Session has expired'],
    ], 401)]);

    app(Nachrichtenversand::class)->stelleEin(whatsappGespraech(), 'Antwort');

    expect($aufbau->verbindung->fresh()?->status)->toBe(ConnectionStatus::Expired)
        ->and(Message::query()->where('direction', 'outbound')->firstOrFail()->failure)->toBe('token_invalid');
});

it('wiederholt bei einem Rate Limit, ohne die Verbindung anzufassen', function (): void {
    $aufbau = new WhatsAppAufbau;

    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['code' => 4, 'message' => 'Application request limit reached'],
    ], 429)]);

    app(Nachrichtenversand::class)->stelleEin(whatsappGespraech(), 'Antwort');

    $nachricht = Message::query()->where('direction', 'outbound')->firstOrFail();

    // Eingereiht geblieben: die Queue versucht es mit wachsendem Abstand.
    expect($nachricht->status)->toBe(MessageStatus::Queued)
        ->and($nachricht->failure)->toBeNull()
        ->and($aufbau->verbindung->fresh()?->status)->toBe(ConnectionStatus::Active);
});

it('lehnt eine Antwort ohne Kennung ab', function (): void {
    // Ein 200 ohne Kennung ist keine Zustellung, die sich nachhalten laesst
    // -- und ohne Kennung kaeme auch keine Rueckmeldung an.
    new WhatsAppAufbau;

    Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp'])]);

    app(Nachrichtenversand::class)->stelleEin(whatsappGespraech(), 'Antwort');

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->failure)->toBe('no_message_id');
});

/* Templates ---------------------------------------------------------------- */

it('legt die Templatevariablen verschluesselt ab', function (): void {
    new WhatsAppAufbau;
    antwortetMitKennung();

    $konversation = whatsappGespraech();

    $nachricht = app(Nachrichtenversand::class)->stelleTemplateEin(
        $konversation,
        genehmigtesTemplate(),
        ['Annika Müller', '13.01. um 10:00 Uhr'],
    );

    expect($nachricht->templatewerte())->toBe(['Annika Müller', '13.01. um 10:00 Uhr']);

    $roh = (string) DB::table('messages')->where('id', $nachricht->getRawOriginal('id'))->value('template_variables');

    expect($roh)->not->toContain('Annika');
});

it('zeigt im Verlauf die Vorschau mit eingesetzten Werten', function (): void {
    new WhatsAppAufbau;
    antwortetMitKennung();

    $nachricht = app(Nachrichtenversand::class)->stelleTemplateEin(
        whatsappGespraech(),
        genehmigtesTemplate(),
        ['Annika Müller', '13.01. um 10:00 Uhr'],
    );

    expect($nachricht->body)->toBe('Guten Tag Annika Müller, Ihr Termin am 13.01. um 10:00 Uhr steht.');
});

it('sperrt ein Template bei leerem Kontingent, aber nie eine Antwort im Fenster', function (): void {
    // **Entscheidung B12.** Eine Praxis darf nie daran gehindert werden,
    // einer Patientin zu antworten -- gesperrt wird nur, was Geld kostet.
    $aufbau = new WhatsAppAufbau;
    antwortetMitKennung();

    config()->set('mrs.billing.included.messages', 0);

    $geschlossen = whatsappGespraech(imFenster: false);

    app(Einwilligungen::class)->erteile(
        $geschlossen->channelIdentity,
        ConsentType::WhatsApp,
        'buchung-2027-01',
        'Ich möchte Nachrichten über WhatsApp erhalten.',
    );

    app(Nachrichtenversand::class)->stelleTemplateEin($geschlossen, genehmigtesTemplate(), ['Annika', 'morgen']);

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->failure)->toBe('quota_exhausted');

    Http::assertNothingSent();

    // Dieselbe Praxis, ein anderes Gespraech mit offenem Fenster: die
    // Antwort geht hinaus.
    $offen = whatsappGespraech(kennung: '4915199999999');

    app(Nachrichtenversand::class)->stelleEin($offen, 'Gern, morgen um 10 Uhr.');

    expect(Message::query()->where('direction', 'outbound')->where('status', 'sent')->count())->toBe(1);
});
