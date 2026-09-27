<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// Ohne RefreshDatabase: die Migration wird zurueck- und wieder vorgerollt.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| WP-06b, Abnahmekriterium 3 -- Fassung 1 aus dem, was bis hier galt
|--------------------------------------------------------------------------
|
| Bis hier standen Preise und Kontingente in `config/mrs.php`, die Preis-IDs
| in `config/services.php`. Die Migration macht daraus Fassung 1 -- und jedes
| bestehende Abo zeigt darauf, sonst rechnete es ab dem Ausrollen mit einer
| Fassung, die es nie abgeschlossen hat.
|
*/

const PAKET_MIGRATION = 'database/migrations/2026_09_27_140000_paketfassungen.php';

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    app(TenantContext::class)->forget();
});

it('legt Fassung 1 aus der bisherigen Konfiguration an und haengt jedes Abo daran', function (): void {
    Artisan::call('migrate:rollback', ['--path' => PAKET_MIGRATION, '--force' => true]);

    config()->set('mrs.billing.prices.base_cents', 12345);
    config()->set('mrs.billing.included.agent_runs', 321);
    config()->set('mrs.billing.trial_days', 17);
    config()->set('services.stripe.price_id', 'price_aus_der_umgebung');
    config()->set('services.stripe.image_price_id', '');

    $mandant = app(TenantContext::class);

    $praxen = collect(['Bezahlt', 'Testphase'])->map(function (string $name) use ($mandant): Organization {
        $praxis = Organization::factory()->create(['name' => $name]);

        $mandant->runAs($praxis, function () use ($name): void {
            $abo = new Subscription;
            $abo->status = $name === 'Bezahlt' ? SubscriptionStatus::Active : SubscriptionStatus::Trialing;
            $abo->save();
        });

        return $praxis;
    });

    Artisan::call('migrate', ['--path' => PAKET_MIGRATION, '--force' => true]);

    $fassung = PlanVersion::query()->sole();

    expect($fassung->number)->toBe(1)
        ->and($fassung->base_cents)->toBe(12345)
        ->and($fassung->included_agent_runs)->toBe(321)
        ->and($fassung->trial_days)->toBe(17)
        ->and($fassung->stripe_price_base)->toBe('price_aus_der_umgebung')
        // Leer heisst: nicht gesetzt -- keine Preis-ID "".
        ->and($fassung->stripe_price_image)->toBeNull()
        ->and($fassung->stripe_state)->toBe(PlanVersion::BEREIT)
        ->and($fassung->gilt())->toBeTrue();

    foreach ($praxen as $praxis) {
        $abo = $mandant->runAs($praxis, fn (): Subscription => Subscription::query()->firstOrFail());

        expect($abo->getAttributes()['plan_version_id'])->toBe($fassung->getKey());
    }
});
