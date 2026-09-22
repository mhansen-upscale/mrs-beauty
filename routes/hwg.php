<?php

declare(strict_types=1);

use App\Http\Controllers\Compliance\HwgController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HWG-Pruefung (WP-30)
|--------------------------------------------------------------------------
|
| Das Differenzierungsmerkmal. Laeuft **vor** jeder Veroeffentlichung.
|
*/

Route::middleware(['auth', 'verified', 'can:brandguide.manage'])->group(function () {
    Route::get('hwg', [HwgController::class, 'index'])->name('hwg.index');
    Route::post('hwg/probe', [HwgController::class, 'pruefen'])->name('hwg.pruefen');
});
