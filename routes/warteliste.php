<?php

declare(strict_types=1);

use App\Http\Controllers\Warteliste\WaitlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Warteliste (WP-25)
|--------------------------------------------------------------------------
|
| Eigene Faehigkeit: wer Termine verwaltet, verwaltet auch die Warteliste --
| sie ist die Gegenseite desselben Kalenders.
|
*/

Route::middleware(['auth', 'verified', 'can:waitlist.manage'])->group(function () {
    Route::get('warteliste', [WaitlistController::class, 'index'])->name('waitlist.index');
    Route::post('warteliste', [WaitlistController::class, 'store'])->name('waitlist.store');
    Route::patch('warteliste/{entry}', [WaitlistController::class, 'update'])->name('waitlist.update');
    Route::delete('warteliste/{entry}', [WaitlistController::class, 'destroy'])->name('waitlist.destroy');

    // Ausloeser 3: ueber eine parallele Zusage entscheidet ein Mensch.
    Route::post('warteliste/klaerung/{offer}/uebergeben', [WaitlistController::class, 'uebergeben'])->name('waitlist.klaerung.uebergeben');
    Route::post('warteliste/klaerung/{offer}/behalten', [WaitlistController::class, 'behalten'])->name('waitlist.klaerung.behalten');
});
