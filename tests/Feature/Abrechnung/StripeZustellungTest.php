<?php

declare(strict_types=1);

use App\Abrechnung\Kontingente;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-34c, Abnahmekriterien 21 bis 23 -- der Webhook, gehaertet
|--------------------------------------------------------------------------
|
| WP-06 AK 15 ("dieselbe Zustellung zweimal aendert nichts zweimal") galt nur,
| weil die meisten Handler zufaellig idempotent waren -- die Aufstockung war
| es nicht. Und Stripe liefert in keiner festen Reihenfolge.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    config()->set('services.stripe.webhook_secret', 'whsec_test');
});

function zustellungspraxis(): Organization
{
    $praxis = alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_1';
    $abo->save();

    ohneMandant();

    return $praxis;
}

/** @param  array<string, mixed>  $daten */
function stripeZustellen(array $daten): void
{
    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();
}

function zugestelltesAbo(Organization $praxis): Subscription
{
    return app(TenantContext::class)->runAs($praxis, fn (): Subscription => Subscription::query()->firstOrFail());
}

it('schreibt eine doppelt zugestellte Aufstockung nur einmal gut', function (): void {
    $praxis = zustellungspraxis();

    $daten = [
        'id' => 'evt_kasse_1',
        'type' => 'checkout.session.completed',
        'created' => 1000,
        'data' => ['object' => [
            'id' => 'cs_1',
            'customer' => 'cus_1',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'metadata' => ['artikel' => 'nachrichten', 'menge' => '1'],
        ]],
    ];

    stripeZustellen($daten);
    stripeZustellen($daten);

    expect(zugestelltesAbo($praxis)->extra_messages)->toBe((int) config('mrs.billing.topup.messages'));
});

it('schreibt eine SEPA-Aufstockung erst gut, wenn das Geld da ist -- und nur einmal', function (): void {
    // **SEPA ist eine verzoegerte Zahlungsart.** Die Kasse schliesst mit
    // `unpaid`, das Geld folgt Tage spaeter als eigenes Ereignis. Bis zum
    // 28.09.2026 kannte der Webhook das zweite nicht: wer per Lastschrift
    // aufstockte, bezahlte und bekam nichts.
    $praxis = zustellungspraxis();

    $kasse = fn (string $kennung, string $art, string $status): array => [
        'id' => $kennung,
        'type' => $art,
        'created' => 1000,
        'data' => ['object' => [
            'id' => 'cs_sepa',
            'customer' => 'cus_1',
            'mode' => 'payment',
            'payment_status' => $status,
            'metadata' => ['artikel' => 'nachrichten', 'menge' => '1'],
        ]],
    ];

    stripeZustellen($kasse('evt_sepa_1', 'checkout.session.completed', 'unpaid'));

    expect(zugestelltesAbo($praxis)->extra_messages)->toBe(0);

    $bezahlt = $kasse('evt_sepa_2', 'checkout.session.async_payment_succeeded', 'paid');

    stripeZustellen($bezahlt);
    stripeZustellen($bezahlt);

    expect(zugestelltesAbo($praxis)->extra_messages)->toBe((int) config('mrs.billing.topup.messages'));
});

it('laesst ein aelteres Ereignis kein neueres ueberschreiben', function (): void {
    $praxis = zustellungspraxis();

    $zustand = fn (int $zeitpunkt, ?array $pause): array => [
        'id' => 'evt_'.$zeitpunkt,
        'type' => 'customer.subscription.updated',
        'created' => $zeitpunkt,
        'data' => ['object' => ['id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'active', 'pause_collection' => $pause]],
    ];

    stripeZustellen($zustand(2000, ['behavior' => 'void']));

    // Das Ereignis von vor der Pause kommt zu spaet an -- und hebt sie nicht
    // still wieder auf.
    stripeZustellen($zustand(1000, null));

    expect(zugestelltesAbo($praxis)->paused_at)->not->toBeNull();
});

it('macht aus Stripes paused keine Kuendigung', function (): void {
    expect(SubscriptionStatus::ausStripe('paused'))->toBe(SubscriptionStatus::Paused)
        ->and(SubscriptionStatus::ausStripe('paused'))->not->toBe(SubscriptionStatus::Canceled);
});

it('liest den Zeitraum auch an den Positionen des Abos', function (): void {
    // Ab der API-Version 2025-03-31.basil stehen current_period_* nicht mehr
    // am Abo, sondern an seinen Positionen.
    $praxis = zustellungspraxis();

    stripeZustellen([
        'id' => 'evt_basil',
        'type' => 'customer.subscription.updated',
        'created' => 1000,
        'data' => ['object' => [
            'id' => 'sub_1',
            'customer' => 'cus_1',
            'status' => 'active',
            'items' => ['data' => [[
                'current_period_start' => CarbonImmutable::parse('2027-01-05 00:00:00')->getTimestamp(),
                'current_period_end' => CarbonImmutable::parse('2027-02-05 00:00:00')->getTimestamp(),
            ]]],
        ]],
    ]);

    expect(zugestelltesAbo($praxis)->period_ends_at?->toDateString())->toBe('2027-02-05');
});

it('haelt den ersten Wechsel auf aktiv fest -- und nur den ersten', function (): void {
    $praxis = zustellungspraxis();

    $aktiv = fn (int $zeitpunkt): array => [
        'id' => 'evt_aktiv_'.$zeitpunkt,
        'type' => 'customer.subscription.updated',
        'created' => $zeitpunkt,
        'data' => ['object' => ['id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'active']],
    ];

    stripeZustellen($aktiv(CarbonImmutable::parse('2027-01-05 10:00:00')->getTimestamp()));
    stripeZustellen($aktiv(CarbonImmutable::parse('2027-01-10 10:00:00')->getTimestamp()));

    // Die Einrichtung zaehlt einmal (WP-34d) -- im Monat des Abschlusses.
    expect(zugestelltesAbo($praxis)->activated_at?->toDateString())->toBe('2027-01-05');
});
