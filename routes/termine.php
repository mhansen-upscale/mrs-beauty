<?php

declare(strict_types=1);

use App\Http\Controllers\Termine\AppointmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Terminverwaltung intern (WP-11)
|--------------------------------------------------------------------------
|
| Die erste Arbeitsoberflaeche des Produkts -- alles bisherige lag unter
| Einstellungen.
|
| Die Uebersicht ist auch fuer eine Behandlerin ohne Verwaltungsrecht
| zugaenglich; sie sieht dann nur ihren eigenen Kalender. Die aendernden
| Routen verlangen appointments.manage, und zwar zusaetzlich in den
| FormRequests: ein Middleware allein liesse eine spaeter vergessene Route
| offen.
|
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('termine', [AppointmentController::class, 'index'])->name('appointments.index');

    Route::middleware('can:appointments.manage')->group(function () {
        Route::post('termine', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::patch('termine/{appointment}/verschieben', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
        Route::patch('termine/{appointment}/status', [AppointmentController::class, 'status'])->name('appointments.status');
        Route::delete('termine/{appointment}', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
    });
});
