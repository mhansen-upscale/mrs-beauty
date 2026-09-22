<?php

declare(strict_types=1);

use App\Http\Controllers\Marke\MarkeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marke (WP-29)
|--------------------------------------------------------------------------
|
| Der Brand Guide. Woraus WP-31 seine Vorschlaege macht -- und woher WP-30
| die verbotenen Begriffe nimmt.
|
*/

Route::middleware(['auth', 'verified', 'can:brandguide.manage'])->group(function () {
    Route::get('marke', [MarkeController::class, 'index'])->name('marke.index');
    Route::put('marke', [MarkeController::class, 'speichern'])->name('marke.speichern');

    Route::post('marke/begriffe', [MarkeController::class, 'begriffAnlegen'])->name('marke.begriff.anlegen');
    Route::delete('marke/begriffe/{begriff}', [MarkeController::class, 'begriffEntfernen'])->name('marke.begriff.entfernen');

    Route::post('marke/referenzen', [MarkeController::class, 'referenzAnlegen'])->name('marke.referenz.anlegen');
    Route::delete('marke/referenzen/{referenz}', [MarkeController::class, 'referenzEntfernen'])->name('marke.referenz.entfernen');
});
