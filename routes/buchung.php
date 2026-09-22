<?php

declare(strict_types=1);

use App\Http\Controllers\Buchung\PublicBookingController;
use App\Http\Middleware\ResolvePublicTenant;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Oeffentliche Buchungsseite (WP-12)
|--------------------------------------------------------------------------
|
| Die einzigen Routen des Produkts ohne Anmeldung -- und die einzigen, die
| einen Mandanten aus der URL aufloesen. ResolvePublicTenant liest dafuer den
| Slug und **nur** den Slug.
|
| Drosselung, weil ein oeffentliches Formular Kontakte und Termine anlegt:
| ohne sie ist die Seite ein Werkzeug, um den Kalender einer Praxis zu
| fluten.
|
*/

Route::prefix('buchen/{praxis}')
    ->middleware(ResolvePublicTenant::class)
    ->group(function () {
        Route::get('/', [PublicBookingController::class, 'show'])->name('buchung.zeigen');

        Route::middleware('throttle:20,1')->group(function () {
            Route::post('reservieren', [PublicBookingController::class, 'reserve'])->name('buchung.reservieren');
            Route::delete('reservieren', [PublicBookingController::class, 'release'])->name('buchung.freigeben');
        });

        Route::post('/', [PublicBookingController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('buchung.buchen');

        Route::get('bestaetigt', [PublicBookingController::class, 'confirmed'])->name('buchung.bestaetigt');
    });
