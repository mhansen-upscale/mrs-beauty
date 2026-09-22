<?php

declare(strict_types=1);

use App\Http\Controllers\Backoffice\BackofficeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Backoffice des Betreibers (WP-34)
|--------------------------------------------------------------------------
|
| **Eine eigene Mittelschicht, keine Faehigkeit.** Faehigkeiten haengen an
| Rollen innerhalb einer Praxis; der Betreiber gehoert zu keiner. Wer hier
| hereinkommt, arbeitet ueber Mandantengrenzen hinweg -- das ist kein
| Abstufungs-, sondern ein Grundsatzunterschied (Regel 1).
|
| Gezeigt werden Zustaende und Zahlen. Wer in eine Praxis hineinsehen muss,
| geht ueber die Impersonation aus WP-05.
|
*/

Route::middleware(['auth', 'verified', 'super-admin'])->prefix('backoffice')->group(function () {
    Route::get('/', [BackofficeController::class, 'index'])->name('backoffice.index');
    Route::get('{organisation}', [BackofficeController::class, 'show'])->name('backoffice.show');

    Route::post('{organisation}/sperren', [BackofficeController::class, 'sperren'])->name('backoffice.sperren');
    Route::post('{organisation}/entsperren', [BackofficeController::class, 'entsperren'])->name('backoffice.entsperren');
    Route::post('{organisation}/gutschrift', [BackofficeController::class, 'gutschreiben'])->name('backoffice.gutschrift');
});
