<?php

declare(strict_types=1);

use App\Abrechnung\Kontingente;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| mrs:stripe-einrichten -- nur noch Schluessel eintragen (28.09.2026)
|--------------------------------------------------------------------------
|
| Bis hier entstanden Gutschein, Webhook-Endpunkt und Kundenportal von Hand im
| Dashboard, je Umgebung -- und der Endpunkt musste auf genau der Version
| stehen, die der Client festnagelt, die das Dashboard aber nicht unbedingt
| anbietet. Jetzt: Schluessel setzen, Befehl ausfuehren, das ausgegebene
| Webhook-Geheimnis setzen.
|
| Das Stripe-Konto ist hier ein kleiner Stand im Speicher, damit ein zweiter
| Lauf sieht, was der erste angelegt hat.
|
*/

beforeEach(function (): void {
    config()->set('services.stripe.key', 'sk_test_einrichten');
    config()->set('services.stripe.url', 'https://stripe.test');
    config()->set('services.stripe.api_version', '2025-02-24.acacia');
    config()->set('services.stripe.free_month_coupon', 'mrs_gratismonat');

    URL::forceRootUrl('https://app.mrs.example');
    URL::forceScheme('https');
});

/**
 * Ein Stripe-Konto im Speicher.
 *
 * @param  array<string, mixed>  $anfang
 * @return ArrayObject<string, mixed>
 */
function stripekonto(array $anfang = []): ArrayObject
{
    /** @var ArrayObject<string, mixed> $konto */
    $konto = new ArrayObject(['webhooks' => [], 'gutscheine' => [], 'portale' => [], 'steuer' => 'active', ...$anfang]);

    Http::fake(function (Request $anfrage) use ($konto) {
        $pfad = (string) parse_url($anfrage->url(), PHP_URL_PATH);
        $methode = $anfrage->method();
        $daten = $anfrage->data();

        if ($pfad === '/v1/webhook_endpoints' && $methode === 'GET') {
            return Http::response(['data' => array_values((array) $konto['webhooks'])]);
        }

        if ($pfad === '/v1/webhook_endpoints' && $methode === 'POST') {
            $webhooks = (array) $konto['webhooks'];
            $kennung = 'we_'.(count($webhooks) + 1);
            $webhooks[$kennung] = [
                'id' => $kennung,
                'url' => $daten['url'],
                'api_version' => $daten['api_version'],
                'enabled_events' => $daten['enabled_events'],
            ];
            $konto['webhooks'] = $webhooks;

            return Http::response([...$webhooks[$kennung], 'secret' => 'whsec_'.$kennung]);
        }

        if (preg_match('#^/v1/webhook_endpoints/(we_\d+)$#', $pfad, $treffer) === 1) {
            $webhooks = (array) $konto['webhooks'];

            if ($methode === 'DELETE') {
                unset($webhooks[$treffer[1]]);
            } else {
                $webhooks[$treffer[1]]['enabled_events'] = $daten['enabled_events'];
            }

            $konto['webhooks'] = $webhooks;

            return Http::response(['id' => $treffer[1]]);
        }

        if (preg_match('#^/v1/coupons/(.+)$#', $pfad, $treffer) === 1) {
            return in_array($treffer[1], (array) $konto['gutscheine'], true)
                ? Http::response(['id' => $treffer[1]])
                : Http::response(['error' => ['code' => 'resource_missing', 'message' => 'No such coupon']], 404);
        }

        if ($pfad === '/v1/coupons' && $methode === 'POST') {
            $konto['gutscheine'] = [...(array) $konto['gutscheine'], $daten['id']];

            return Http::response(['id' => $daten['id']]);
        }

        if ($pfad === '/v1/billing_portal/configurations' && $methode === 'GET') {
            return Http::response(['data' => array_values((array) $konto['portale'])]);
        }

        if (str_starts_with($pfad, '/v1/billing_portal/configurations') && $methode === 'POST') {
            $portale = (array) $konto['portale'];
            $kennung = preg_match('#/(bpc_\d+)$#', $pfad, $treffer) === 1 ? $treffer[1] : 'bpc_'.(count($portale) + 1);
            $portale[$kennung] = ['id' => $kennung, 'metadata' => ['mrs' => $daten['metadata[mrs]'] ?? null]];
            $konto['portale'] = $portale;

            return Http::response($portale[$kennung]);
        }

        if ($pfad === '/v1/billing_portal/sessions') {
            return Http::response(['url' => 'https://portal.stripe.test/p/1']);
        }

        if ($pfad === '/v1/tax/settings') {
            return Http::response(['status' => $konto['steuer']]);
        }

        return Http::response(['error' => ['message' => 'Nicht im Testkonto: '.$methode.' '.$pfad]], 500);
    });

    return $konto;
}

/**
 * Der Befehl mit Erwartungen an die Ausgabe. Pest\Laravel\artisan() liefert
 * PendingCommand **oder** int -- pruefen laesst sich nur am einen.
 *
 * @param  array<string, mixed>  $optionen
 */
function einrichtung(array $optionen = []): PendingCommand
{
    $befehl = artisan('mrs:stripe-einrichten', $optionen);
    assert($befehl instanceof PendingCommand);

    return $befehl;
}

function schreibendeAufrufe(): int
{
    return Http::recorded(fn (Request $anfrage): bool => in_array($anfrage->method(), ['POST', 'DELETE'], true))->count();
}

it('richtet ein leeres Konto ein und nennt das Webhook-Geheimnis', function (): void {
    $konto = stripekonto();

    einrichtung()
        ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET=whsec_we_1')
        ->doesntExpectOutputToContain('sk_test_einrichten')
        ->assertSuccessful();

    $webhook = array_values((array) $konto['webhooks'])[0];

    expect($webhook['url'])->toBe('https://app.mrs.example/webhooks/stripe')
        ->and($webhook['api_version'])->toBe('2025-02-24.acacia')
        ->and($webhook['enabled_events'])->toBe(config('mrs.billing.stripe_ereignisse'))
        ->and($konto['gutscheine'])->toBe(['mrs_gratismonat'])
        ->and(array_values((array) $konto['portale'])[0]['metadata']['mrs'])->toBe('portal');

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/coupons')
        && (string) ($anfrage->data()['percent_off'] ?? '') === '100'
        && ($anfrage->data()['duration'] ?? null) === 'once');

    // Kein Tarifwechsel im Portal -- das Paket wird im Produkt gepflegt (B20).
    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/billing_portal/configurations')
        && ($anfrage->data()['features[subscription_update][enabled]'] ?? null) === 'false'
        && ($anfrage->data()['features[subscription_cancel][mode]'] ?? null) === 'at_period_end');

    // Mit festgenagelter Version, wie jeder Aufruf des Clients.
    Http::assertSent(fn (Request $anfrage): bool => $anfrage->header('Stripe-Version') === ['2025-02-24.acacia']);
});

it('legt beim zweiten Lauf nichts an und nennt kein Geheimnis', function (): void {
    $konto = stripekonto();

    expect(Artisan::call('mrs:stripe-einrichten'))->toBe(0)
        ->and(Artisan::output())->toContain('STRIPE_WEBHOOK_SECRET=');

    expect(Artisan::call('mrs:stripe-einrichten'))->toBe(0)
        ->and(Artisan::output())->not->toContain('STRIPE_WEBHOOK_SECRET=');

    expect($konto['webhooks'])->toHaveCount(1)
        ->and($konto['gutscheine'])->toHaveCount(1)
        ->and($konto['portale'])->toHaveCount(1);
});

it('gleicht die Ereignisse eines bestehenden Endpunkts ab', function (): void {
    $konto = stripekonto(['webhooks' => ['we_1' => [
        'id' => 'we_1',
        'url' => 'https://app.mrs.example/webhooks/stripe',
        'api_version' => '2025-02-24.acacia',
        'enabled_events' => ['checkout.session.completed'],
    ]]]);

    einrichtung()
        ->doesntExpectOutputToContain('STRIPE_WEBHOOK_SECRET=')
        ->assertSuccessful();

    expect($konto['webhooks']['we_1']['enabled_events'])->toBe(config('mrs.billing.stripe_ereignisse'));
});

it('meldet einen Endpunkt auf anderer Version, ohne ihn anzufassen', function (): void {
    $konto = stripekonto(['webhooks' => ['we_1' => [
        'id' => 'we_1',
        'url' => 'https://app.mrs.example/webhooks/stripe',
        'api_version' => '2025-06-30.basil',
        'enabled_events' => config('mrs.billing.stripe_ereignisse'),
    ]]]);

    einrichtung()
        ->expectsOutputToContain('steht auf 2025-06-30.basil statt 2025-02-24.acacia -- mit --webhook-neu neu anlegen')
        ->assertFailed();

    expect($konto['webhooks'])->toHaveKey('we_1')
        ->and($konto['webhooks'])->toHaveCount(1);

    Http::assertNotSent(fn (Request $anfrage): bool => str_contains($anfrage->url(), '/v1/webhook_endpoints') && $anfrage->method() !== 'GET');
});

it('ersetzt den Endpunkt mit --webhook-neu -- erst der neue, dann weg mit dem alten', function (): void {
    $konto = stripekonto(['webhooks' => ['we_1' => [
        'id' => 'we_1',
        'url' => 'https://app.mrs.example/webhooks/stripe',
        'api_version' => '2025-06-30.basil',
        'enabled_events' => config('mrs.billing.stripe_ereignisse'),
    ]]]);

    einrichtung(['--webhook-neu' => true])
        ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET=whsec_we_2')
        ->assertSuccessful();

    expect(array_keys((array) $konto['webhooks']))->toBe(['we_2'])
        ->and($konto['webhooks']['we_2']['api_version'])->toBe('2025-02-24.acacia');
});

it('bricht ohne Schluessel ab, ohne Stripe zu fragen', function (): void {
    config()->set('services.stripe.key', '');
    stripekonto();

    einrichtung()
        ->expectsOutputToContain('STRIPE_SECRET')
        ->assertFailed();

    Http::assertNothingSent();
});

it('legt lokal keinen Webhook an und verweist auf stripe listen', function (): void {
    URL::forceRootUrl('http://localhost');
    $konto = stripekonto();

    einrichtung()
        ->expectsOutputToContain('stripe listen')
        ->assertSuccessful();

    expect($konto['webhooks'])->toBeEmpty()
        ->and($konto['gutscheine'])->toHaveCount(1);
});

it('fragt in Live vor dem ersten Schreiben nach', function (): void {
    config()->set('services.stripe.key', 'sk_live_einrichten');
    stripekonto();

    einrichtung()
        ->expectsConfirmation('Das ist das Live-Konto. Jetzt bei Stripe anlegen?', 'no')
        ->assertFailed();

    expect(schreibendeAufrufe())->toBe(0);
});

it('schreibt in Live mit --force ohne Rueckfrage', function (): void {
    config()->set('services.stripe.key', 'sk_live_einrichten');
    $konto = stripekonto();

    einrichtung(['--force' => true])
        ->doesntExpectOutputToContain('sk_live_einrichten')
        ->assertSuccessful();

    expect($konto['gutscheine'])->toHaveCount(1);
});

it('warnt, wenn Stripe Tax nicht rechnet', function (): void {
    // Ohne Stripe Tax lehnt Stripe jede Kasse ab -- sie laesst die
    // Umsatzsteuer berechnen (WP-06, 28.09.2026).
    stripekonto(['steuer' => 'pending']);

    einrichtung()
        ->expectsOutputToContain('Stripe Tax')
        ->assertSuccessful();
});

it('nennt den Schritt im Backoffice, solange der Paketfassung die Preise fehlen', function (): void {
    stripekonto();

    Artisan::call('mrs:stripe-einrichten');

    expect(Artisan::output())->toContain('im Backoffice unter Paket die Fassung speichern');

    neuesPaket([
        'stripe_price_base' => 'price_abo',
        'stripe_price_setup' => 'price_einrichtung',
        'stripe_price_topup' => 'price_block',
        'stripe_price_image' => 'price_bild',
    ]);

    Artisan::call('mrs:stripe-einrichten');

    expect(Artisan::output())->not->toContain('Backoffice');
});

it('oeffnet das Kundenportal mit der eigenen Konfiguration', function (): void {
    stripekonto(['portale' => ['bpc_1' => ['id' => 'bpc_1', 'metadata' => ['mrs' => 'portal']]]]);

    $organisation = alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_portal';
    $abo->save();

    actingAs(User::factory()->fuer($organisation, Role::Owner)->create())
        ->post(route('abo.portal'))
        ->assertRedirect('https://portal.stripe.test/p/1');

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/billing_portal/sessions')
        && ($anfrage->data()['configuration'] ?? null) === 'bpc_1'
        && ($anfrage->data()['customer'] ?? null) === 'cus_portal');
});
