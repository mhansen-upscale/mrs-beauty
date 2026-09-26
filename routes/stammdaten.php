<?php

declare(strict_types=1);

use App\Http\Controllers\Katalog\AppointmentTypeController;
use App\Http\Controllers\Katalog\TreatmentController;
use App\Http\Controllers\Stammdaten\LocationController;
use App\Http\Controllers\Stammdaten\PractitionerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Praxis: Standorte, Behandler, Behandlungen, Terminarten
|--------------------------------------------------------------------------
|
| Arbeitsbereiche, keine Einstellungen. Wer Arbeitszeiten pflegt oder einen
| Katalog erweitert, tut das woechentlich -- das gehoert in die
| Hauptnavigation und nicht hinter ein Zahnrad.
|
| Die Faehigkeit wird zusaetzlich in den Controllern geprueft: das Middleware
| allein liesse eine spaeter vergessene Route offen.
|
*/

Route::middleware(['auth', 'verified'])->group(function () {
    // Praxisstammdaten (WP-08)
    Route::middleware('can:masterdata.manage')->group(function () {
        Route::get('standorte', [LocationController::class, 'index'])->name('locations.index');
        Route::post('standorte', [LocationController::class, 'store'])->name('locations.store');
        Route::patch('standorte/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::delete('standorte/{location}', [LocationController::class, 'deactivate'])->name('locations.deactivate');
        Route::put('standorte/{location}/aktivieren', [LocationController::class, 'activate'])->name('locations.activate');
        Route::post('standorte/{location}/schliesszeiten', [LocationController::class, 'storeClosure'])->name('closures.store');
        Route::delete('standorte/{location}/schliesszeiten/{closure}', [LocationController::class, 'destroyClosure'])->name('closures.destroy');

        Route::get('behandler', [PractitionerController::class, 'index'])->name('practitioners.index');
        Route::post('behandler', [PractitionerController::class, 'store'])->name('practitioners.store');
        Route::patch('behandler/{practitioner}', [PractitionerController::class, 'update'])->name('practitioners.update');
        Route::delete('behandler/{practitioner}', [PractitionerController::class, 'deactivate'])->name('practitioners.deactivate');
        Route::put('behandler/{practitioner}/aktivieren', [PractitionerController::class, 'activate'])->name('practitioners.activate');
        Route::post('behandler/{practitioner}/bild', [PractitionerController::class, 'storeAvatar'])->name('practitioners.avatar.store');
        Route::delete('behandler/{practitioner}/bild', [PractitionerController::class, 'destroyAvatar'])->name('practitioners.avatar.destroy');

        Route::post('behandler/{practitioner}/arbeitszeiten', [PractitionerController::class, 'storeWorkingHour'])->name('workinghours.store');
        Route::delete('behandler/{practitioner}/arbeitszeiten/{workingHour}', [PractitionerController::class, 'destroyWorkingHour'])->name('workinghours.destroy');
        Route::post('behandler/{practitioner}/abwesenheiten', [PractitionerController::class, 'storeAbsence'])->name('absences.store');
        Route::delete('behandler/{practitioner}/abwesenheiten/{absence}', [PractitionerController::class, 'destroyAbsence'])->name('absences.destroy');
    });

    // Leistungskatalog und Terminarten (WP-09)
    Route::middleware('can:catalog.manage')->group(function () {
        Route::get('behandlungen', [TreatmentController::class, 'index'])->name('treatments.index');
        Route::post('behandlungen', [TreatmentController::class, 'store'])->name('treatments.store');
        Route::patch('behandlungen/{treatment}', [TreatmentController::class, 'update'])->name('treatments.update');
        Route::delete('behandlungen/{treatment}', [TreatmentController::class, 'deactivate'])->name('treatments.deactivate');
        Route::put('behandlungen/{treatment}/aktivieren', [TreatmentController::class, 'activate'])->name('treatments.activate');
        // Buchungsseite als Pruefgegenstand (WP-30): uebersteuern mit Begruendung (C3).
        Route::post('behandlungen/{treatment}/hwg-uebersteuern', [TreatmentController::class, 'uebersteuern'])->name('treatments.hwg.uebersteuern');

        Route::get('terminarten', [AppointmentTypeController::class, 'index'])->name('appointmenttypes.index');
        Route::post('terminarten', [AppointmentTypeController::class, 'store'])->name('appointmenttypes.store');
        Route::patch('terminarten/{appointmentType}', [AppointmentTypeController::class, 'update'])->name('appointmenttypes.update');
        Route::delete('terminarten/{appointmentType}', [AppointmentTypeController::class, 'deactivate'])->name('appointmenttypes.deactivate');
        Route::put('terminarten/{appointmentType}/aktivieren', [AppointmentTypeController::class, 'activate'])->name('appointmenttypes.activate');
    });
});
