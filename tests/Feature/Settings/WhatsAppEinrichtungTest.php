<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Jobs\WhatsAppPruefen;
use App\Kanaele\WhatsApp\WhatsAppEinrichtung;
use App\Models\ChannelConnection;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-20b, offen: "Einen Kanal einzurichten geht bisher nur ueber die
| Datenbank." Fuer E-Mail steht das Postfach seit WP-20b, fuer WhatsApp seit
| dem 26.09.2026 diese Seite.
|--------------------------------------------------------------------------
|
| **Kein Aufruf an Meta im Anfragezyklus** (B2, Regel 4): gespeichert wird
| sofort, geprueft in der Warteschlange. Das Ergebnis steht danach an der
| Verbindung.
|
| **Das Token geht nie zurueck an die Oberflaeche** -- wie das Passwort des
| Postfachs.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * @param  array<string, string>  $abweichend
 * @return array<string, string>
 */
function whatsappangaben(array $abweichend = []): array
{
    return [
        'waba' => '104857600000001',
        'rufnummer' => '109876500000002',
        'anzeigename' => 'Praxis Dr. Sauer',
        'token' => 'EAAG-systembenutzer-token',
        ...$abweichend,
    ];
}

it('richtet die Verbindung ein und prueft sie in der Warteschlange', function (): void {
    Queue::fake();
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    actingAs($inhaberin)->put(route('whatsapp.update'), whatsappangaben())->assertSessionHasNoErrors();

    $verbindung = ChannelConnection::query()->where('channel', ChannelType::WhatsApp->value)->firstOrFail();

    expect($verbindung->external_id)->toBe('104857600000001')
        ->and($verbindung->sender_id)->toBe('109876500000002')
        ->and($verbindung->access_token)->toBe('EAAG-systembenutzer-token')
        // Ungeprueft, bis Meta geantwortet hat.
        ->and($verbindung->verified_at)->toBeNull();

    // Verschluesselt abgelegt, nicht im Klartext.
    expect((string) DB::table('channel_connections')->value('access_token'))->not->toContain('EAAG');

    Queue::assertPushed(WhatsAppPruefen::class);
});

it('gibt das Token nie an die Oberflaeche zurueck', function (): void {
    Queue::fake();
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    actingAs($inhaberin)->put(route('whatsapp.update'), whatsappangaben());

    $antwort = actingAs($inhaberin)->get(route('whatsapp.edit'))->assertOk();

    expect(json_encode($antwort->viewData('page')))->not->toContain('EAAG');
    $antwort->assertInertia(fn ($seite) => $seite->where('tokenGesetzt', true));
});

it('laesst das Token stehen, wenn das Feld leer bleibt', function (): void {
    Queue::fake();
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    actingAs($inhaberin)->put(route('whatsapp.update'), whatsappangaben());
    actingAs($inhaberin)->put(route('whatsapp.update'), whatsappangaben(['token' => '', 'anzeigename' => 'Praxis Sauer']));

    expect(ChannelConnection::query()->firstOrFail()->access_token)->toBe('EAAG-systembenutzer-token');
});

it('nimmt keine Kennung an, die schon einer anderen Praxis gehoert', function (): void {
    Queue::fake();
    $andere = alsMandant(organisation('Andere Praxis'));
    actingAs(User::factory()->fuer($andere, Role::Owner)->create())->put(route('whatsapp.update'), whatsappangaben());

    $praxis = alsMandant(organisation('Demo-Praxis'));

    // Die Zustellung findet ihre Praxis ueber die WABA-Kennung. Zweimal
    // vergeben, landete die Post der einen bei der anderen.
    actingAs(User::factory()->fuer($praxis, Role::Owner)->create())
        ->put(route('whatsapp.update'), whatsappangaben())
        ->assertSessionHasErrors('waba');
});

it('vermerkt eine erfolgreiche Pruefung und abonniert die Zustellungen', function (): void {
    Queue::fake();
    $praxis = alsMandant(organisation('Demo-Praxis'));
    actingAs(User::factory()->fuer($praxis, Role::Owner)->create())->put(route('whatsapp.update'), whatsappangaben());

    Http::fake([
        'graph.facebook.com/*/109876500000002*' => Http::response([
            'id' => '109876500000002',
            'display_phone_number' => '+49 30 1234567',
            'verified_name' => 'Praxis Dr. Sauer',
        ]),
        'graph.facebook.com/*/104857600000001/subscribed_apps' => Http::response(['success' => true]),
        'graph.facebook.com/*/104857600000001/message_templates*' => Http::response(['data' => []]),
    ]);

    (new WhatsAppPruefen((string) $praxis->uuid))->handle(app(TenantContext::class), app(WhatsAppEinrichtung::class));

    $verbindung = ChannelConnection::query()->firstOrFail();

    expect($verbindung->status)->toBe(ConnectionStatus::Active)
        ->and($verbindung->verified_at)->not->toBeNull()
        ->and($verbindung->last_error)->toBeNull();

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/104857600000001/subscribed_apps')
        && $anfrage->method() === 'POST'
        && $anfrage->hasHeader('Authorization', 'Bearer EAAG-systembenutzer-token'));
});

it('meldet ein abgelehntes Token an der Verbindung, nicht nur im Log', function (): void {
    Queue::fake();
    $praxis = alsMandant(organisation('Demo-Praxis'));
    actingAs(User::factory()->fuer($praxis, Role::Owner)->create())->put(route('whatsapp.update'), whatsappangaben());

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 401)]);

    (new WhatsAppPruefen((string) $praxis->uuid))->handle(app(TenantContext::class), app(WhatsAppEinrichtung::class));

    $verbindung = ChannelConnection::query()->firstOrFail();

    expect($verbindung->status)->not->toBe(ConnectionStatus::Active)
        ->and($verbindung->verified_at)->toBeNull()
        ->and($verbindung->last_error)->not->toBeNull()
        // Ein Kurzgrund, nie der Klartext des Anbieters.
        ->and($verbindung->last_error)->not->toContain('OAuth');
});

it('laesst den Empfang nicht an die Einrichtung', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));

    actingAs(User::factory()->fuer($praxis, Role::Reception)->create())
        ->get(route('whatsapp.edit'))
        ->assertForbidden();
});
