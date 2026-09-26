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
    config()->set('services.stripe.price_id', 'price_abo');
    config()->set('services.stripe.setup_price_id', 'price_einrichtung');

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

    config()->set('services.stripe.topup_price_id', 'price_block');

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
