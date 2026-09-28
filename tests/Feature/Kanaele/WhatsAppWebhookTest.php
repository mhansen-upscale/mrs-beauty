<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Kanaele\MetaSignatur;
use App\Models\ChannelConnection;
use App\Models\ChannelRawEvent;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\WhatsAppAufbau;

/*
|--------------------------------------------------------------------------
| WP-20a, Nachtrag 28.09.2026 -- Abnahmekriterien 29 bis 32
|--------------------------------------------------------------------------
|
| Die Zustellung ueber den Webhook. WhatsAppEmpfangTest prueft den Leser,
| WebhookTest den Endpunkt mit einem Testkanal -- dazwischen lag, was nur
| WhatsApp betrifft: `whatsapp_business_account` fuehrt zum Kanal, und der
| Mandant wird ueber die WABA gefunden, nicht ueber die Rufnummern-ID.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    config(['mrs.meta.app_secret' => 'app-geheimnis']);
});

/**
 * Eine Zustellung, wie Meta sie an den Webhook schickt: verpackt, signiert,
 * und signiert **ueber genau die Bytes**, die ankommen.
 *
 * @param  array<string, mixed>  $eintrag
 * @return TestResponse<Response>
 */
function whatsappUeberMeta(array $eintrag, string $geheimnis = 'app-geheimnis'): TestResponse
{
    $rumpf = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [$eintrag]]);

    return call('POST', route('meta.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => MetaSignatur::bilde($rumpf, $geheimnis),
    ], $rumpf);
}

/**
 * @return array<string, mixed>
 */
function textzustellung(string $kennung = 'wamid.1'): array
{
    return WhatsAppAufbau::zustellung([
        'id' => $kennung,
        'timestamp' => '1800000000',
        'type' => 'text',
        'text' => ['body' => 'Guten Tag, ist morgen etwas frei?'],
    ]);
}

it('legt eine Zustellung ueber den Webhook bei der Praxis an, deren WABA sie traegt', function (): void {
    $aufbau = new WhatsAppAufbau;

    // Eine zweite Praxis mit eigener Rufnummer -- sie darf nichts sehen.
    $andere = alsMandant(organisation('Zweite Praxis'));
    $fremde = new ChannelConnection;
    $fremde->channel = ChannelType::WhatsApp;
    $fremde->status = ConnectionStatus::Active;
    $fremde->external_id = 'waba-0815';
    $fremde->sender_id = 'nummer-11';
    $fremde->display_name = 'Zweite Praxis';
    $fremde->access_token = 'anderes-token';
    $fremde->save();

    ohneMandant();

    whatsappUeberMeta(textzustellung())->assertOk();

    alsMandant($aufbau->organisation);

    $nachricht = Message::query()->firstOrFail();

    expect(Message::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1)
        ->and($nachricht->channel)->toBe(ChannelType::WhatsApp)
        ->and($nachricht->external_id)->toBe('wamid.1')
        ->and($nachricht->body)->toBe('Guten Tag, ist morgen etwas frei?');

    alsMandant($andere);

    expect(ChannelRawEvent::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0);
});

it('findet ueber die Rufnummern-ID keinen Mandanten und quittiert trotzdem', function (): void {
    // "Die Zustellung traegt die WABA-Kennung, der Versand die
    // Rufnummern-ID." Wer die beiden verwechselt, findet keine Praxis -- und
    // Meta darf das nicht als Fehler sehen, sonst wiederholt es endlos.
    $aufbau = new WhatsAppAufbau;
    ohneMandant();

    $eintrag = textzustellung();
    $eintrag['id'] = WhatsAppAufbau::RUFNUMMER;

    whatsappUeberMeta($eintrag)->assertOk();

    alsMandant($aufbau->organisation);

    expect(ChannelRawEvent::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('verwirft eine WhatsApp-Zustellung mit falscher Signatur, ohne etwas zu speichern', function (): void {
    $aufbau = new WhatsAppAufbau;
    ohneMandant();

    whatsappUeberMeta(textzustellung(), geheimnis: 'geraten')->assertForbidden();

    alsMandant($aufbau->organisation);

    expect(ChannelRawEvent::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
});

it('macht aus derselben Zustellung ueber den Webhook eine Nachricht, nicht zwei', function (): void {
    $aufbau = new WhatsAppAufbau;
    ohneMandant();

    whatsappUeberMeta(textzustellung())->assertOk();
    whatsappUeberMeta(textzustellung())->assertOk();

    alsMandant($aufbau->organisation);

    expect(ChannelRawEvent::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(1);
});
