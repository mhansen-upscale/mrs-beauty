<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

// Ohne RefreshDatabase: die Migration wird zurueck- und wieder vorgerollt.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| WP-34c, Abnahmekriterium 11 -- das Ausrollen sperrt niemanden
|--------------------------------------------------------------------------
|
| Bis hier wurde das Ende der Testphase nicht durchgesetzt. Wer seit mehr als
| 30 Tagen ohne Abo arbeitet -- also womoeglich jede Pilotpraxis --, waere mit
| dem Ausrollen gesperrt. Die Migration gibt ihnen eine Gnadenfrist.
|
*/

const GNADENFRIST_MIGRATION = 'database/migrations/2026_09_27_130100_testphase_gnadenfrist.php';

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    app(TenantContext::class)->forget();
});

it('gibt jeder abgelaufenen Testphase eine Gnadenfrist und laesst Bezahltes in Ruhe', function (): void {
    Artisan::call('migrate:rollback', ['--path' => GNADENFRIST_MIGRATION, '--force' => true]);

    $mandant = app(TenantContext::class);
    $vorGeraumerZeit = CarbonImmutable::now()->subDays(60);

    // Ohne Abo-Zeile, seit 60 Tagen dabei.
    $ohneZeile = Organization::factory()->create(['name' => 'Ohne Zeile']);
    $ohneZeile->forceFill(['created_at' => $vorGeraumerZeit])->save();

    // Mit Zeile, Testphase seit Wochen vorbei.
    $abgelaufen = Organization::factory()->create(['name' => 'Abgelaufen']);
    $mandant->runAs($abgelaufen, function (): void {
        $abo = new Subscription;
        $abo->status = SubscriptionStatus::Trialing;
        $abo->trial_ends_at = CarbonImmutable::now()->subDays(20);
        $abo->save();
    });

    // Bezahlt -- bleibt, wie es ist.
    $bezahlt = Organization::factory()->create(['name' => 'Bezahlt']);
    $mandant->runAs($bezahlt, function (): void {
        $abo = new Subscription;
        $abo->status = SubscriptionStatus::Active;
        $abo->stripe_subscription_id = 'sub_1';
        $abo->save();
    });

    Artisan::call('migrate', ['--path' => GNADENFRIST_MIGRATION, '--force' => true]);

    $gnadenfrist = CarbonImmutable::now()->addDays((int) config('mrs.billing.trial_gnadenfrist_tage'))->toDateString();

    foreach ([$ohneZeile, $abgelaufen, $bezahlt] as $praxis) {
        $abo = $mandant->runAs($praxis, fn (): ?Subscription => Subscription::query()->first());

        expect($abo)->not->toBeNull("{$praxis->name} hat keine Abo-Zeile.")
            ->and($abo?->zugang()->sperrtZugang())->toBeFalse("{$praxis->name} ist nach dem Ausrollen gesperrt.");
    }

    expect($mandant->runAs($ohneZeile, fn () => Subscription::query()->first()?->trial_ends_at?->toDateString()))->toBe($gnadenfrist)
        ->and($mandant->runAs($abgelaufen, fn () => Subscription::query()->first()?->trial_ends_at?->toDateString()))->toBe($gnadenfrist)
        ->and($mandant->runAs($bezahlt, fn () => Subscription::query()->first()?->trial_ends_at))->toBeNull();
});
