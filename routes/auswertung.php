<?php

declare(strict_types=1);

use App\Http\Controllers\Attribution\AuswertungController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auswertung (WP-32b)
|--------------------------------------------------------------------------
|
| Die Zahl, die das Abo rechtfertigt.
|
*/

Route::middleware(['auth', 'verified', 'can:insights.view'])->group(function () {
    Route::get('auswertung', [AuswertungController::class, 'index'])->name('auswertung.index');
});
