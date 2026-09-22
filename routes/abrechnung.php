<?php

declare(strict_types=1);

use App\Http\Controllers\Abrechnung\AboController;
use App\Http\Controllers\Abrechnung\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Abo und Abrechnung (WP-06)
|--------------------------------------------------------------------------
|
| Die Faehigkeit `billing.manage` hat nur die Inhaberin: wer Termine bucht,
| schliesst keine Vertraege.
|
| Die Zustellung von Stripe kommt ohne Anmeldung an und weist sich ueber die
| Signatur aus -- die Ausnahme vom CSRF-Schutz steht in bootstrap/app.php.
|
*/

Route::middleware(['auth', 'verified', 'can:billing.manage'])->group(function () {
    Route::get('settings/abo', [AboController::class, 'edit'])->name('abo.edit');
    Route::post('settings/abo/kasse', [AboController::class, 'kasse'])->name('abo.kasse');
    Route::post('settings/abo/portal', [AboController::class, 'portal'])->name('abo.portal');
});

Route::post('webhooks/stripe', StripeWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('stripe.webhook');
