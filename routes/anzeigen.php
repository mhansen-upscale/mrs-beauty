<?php

declare(strict_types=1);

use App\Http\Controllers\Anzeigen\AnzeigenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Anzeigenvorschlaege (WP-31)
|--------------------------------------------------------------------------
|
| Entwuerfe, keine Anzeigen. Der Weg zu einer laufenden Anzeige fuehrt ueber
| WP-27.
|
*/

Route::middleware(['auth', 'verified', 'can:campaigns.manage'])->group(function () {
    Route::get('anzeigen', [AnzeigenController::class, 'index'])->name('anzeigen.index');

    // Eine Anzeige, die jemand selbst schreibt -- ohne auf Montag zu warten.
    Route::post('anzeigen', [AnzeigenController::class, 'speichern'])->name('anzeigen.speichern');

    Route::post('anzeigen/{vorschlag}/freigeben', [AnzeigenController::class, 'freigeben'])->name('anzeigen.freigeben');
    Route::post('anzeigen/{vorschlag}/verwerfen', [AnzeigenController::class, 'verwerfen'])->name('anzeigen.verwerfen');

    // Zurueck zum Entwurf -- aus dem Verworfenen wie aus der Freigabe.
    Route::post('anzeigen/{vorschlag}/zurueckholen', [AnzeigenController::class, 'zurueckholen'])->name('anzeigen.zurueckholen');
    Route::post('anzeigen/{vorschlag}/uebersteuern', [AnzeigenController::class, 'uebersteuern'])->name('anzeigen.uebersteuern');
    Route::post('anzeigen/{vorschlag}/bild', [AnzeigenController::class, 'bildAnfordern'])->name('anzeigen.bild.anfordern');
    Route::get('anzeigen/{vorschlag}/bild', [AnzeigenController::class, 'bild'])->name('anzeigen.bild');

    // Der letzte Meter: aus dem freigegebenen Entwurf wird eine Anzeige
    // (WP-27b). Angestossen, nicht abgewartet.
    Route::post('anzeigen/{vorschlag}/schalten', [AnzeigenController::class, 'schalten'])->name('anzeigen.schalten');
});
