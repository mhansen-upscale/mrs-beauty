<?php

declare(strict_types=1);

use App\Abrechnung\Kontingente;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Preismodell (docs/produkt.md, festgelegt am 26.09.2026)
|--------------------------------------------------------------------------
|
| Abgerechnet wird bei Stripe. Das Produkt nennt die Preise nur -- und nimmt
| die Einrichtung einmal, beim ersten Abschluss, mit in die Kasse.
|
*/

beforeEach(function (): void {
    config()->set('services.stripe.key', 'sk_test_preise');
    config()->set('services.stripe.url', 'https://stripe.test');
    neuesPaket(['stripe_price_base' => 'price_abo', 'stripe_price_setup' => 'price_einrichtung']);

    Http::fake([
        'stripe.test/v1/customers' => Http::response(['id' => 'cus_preise']),
        'stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/s/1']),
    ]);
});

it('nimmt die Einrichtung beim ersten Abschluss mit in die Kasse', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)
        ->post(route('abo.kasse'), ['was' => 'abo'])
        ->assertRedirect('https://checkout.stripe.test/s/1');

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/checkout/sessions')
        && ($anfrage->data()['line_items[0][price]'] ?? null) === 'price_abo'
        && ($anfrage->data()['line_items[1][price]'] ?? null) === 'price_einrichtung');
});

it('laesst Stripe die Umsatzsteuer rechnen -- im Abo wie in der Aufstockung', function (string $was): void {
    // **Netto zuzueglich USt.** (docs/produkt.md). Die Preise tragen
    // `tax_behavior=exclusive`; ohne `automatic_tax` zoege Stripe trotzdem
    // nur den Nettobetrag ein. Bis zum 28.09.2026 fehlte es.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    neuesPaket(['stripe_price_topup' => 'price_block']);

    actingAs($inhaberin)->post(route('abo.kasse'), ['was' => $was]);

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/checkout/sessions')
        && ($anfrage->data()['automatic_tax[enabled]'] ?? null) === 'true'
        && ($anfrage->data()['tax_id_collection[enabled]'] ?? null) === 'true'
        && ($anfrage->data()['customer_update[address]'] ?? null) === 'auto'
        && ($anfrage->data()['customer_update[name]'] ?? null) === 'auto'
        && ($anfrage->data()['billing_address_collection'] ?? null) === 'required');
})->with(['abo', 'nachrichten']);

it('berechnet die Einrichtung nach einer Kuendigung nicht noch einmal', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    $abo = app(Kontingente::class)->abo();
    $abo->stripe_subscription_id = 'sub_frueher';
    $abo->save();

    actingAs($inhaberin)->post(route('abo.kasse'), ['was' => 'abo']);

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/checkout/sessions')
        && ! array_key_exists('line_items[1][price]', $anfrage->data()));
});

it('nimmt keine Einrichtung in eine Aufstockung', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    neuesPaket(['stripe_price_topup' => 'price_block']);

    actingAs($inhaberin)->post(route('abo.kasse'), ['was' => 'nachrichten']);

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/checkout/sessions')
        && ($anfrage->data()['line_items[0][price]'] ?? null) === 'price_block'
        && ! array_key_exists('line_items[1][price]', $anfrage->data()));
});

it('nennt den Blockpreis, bevor jemand aufstockt', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($inhaberin)
        ->get(route('abo.edit'))
        ->assertInertia(fn ($seite) => $seite
            ->where('blockpreisCent', 5900)
            ->where('blockmengen.nachrichten', 250)
            ->where('blockmengen.agentenlaeufe', 600)
        );
});
