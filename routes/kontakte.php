<?php

declare(strict_types=1);

use App\Http\Controllers\Kontakte\ContactController;
use App\Http\Controllers\Kontakte\NotizController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kontakte und Kanalidentitaeten (WP-16)
|--------------------------------------------------------------------------
|
| `contacts`, nicht `patients` (Entscheidung D1) -- der Name haelt die Grenze
| im Code sichtbar: dieses Produkt fuehrt keine Patientenakte, sondern einen
| Terminkalender.
|
| Die Faehigkeit wird zusaetzlich in den Controllern und Requests geprueft:
| das Middleware allein liesse eine spaeter vergessene Route offen.
|
*/

Route::middleware(['auth', 'verified', 'can:contacts.manage'])->group(function () {
    Route::get('kontakte', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('kontakte', [ContactController::class, 'store'])->name('contacts.store');
    Route::patch('kontakte/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('kontakte/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::get('kontakte/{contact}/auskunft', [ContactController::class, 'export'])->name('contacts.export');

    Route::post('kontakte/{contact}/kanaele', [ContactController::class, 'storeIdentity'])->name('identities.store');
    Route::delete('kontakte/{contact}/kanaele/{identity}', [ContactController::class, 'destroyIdentity'])->name('identities.destroy');

    Route::post('kontakte/{contact}/zusammenfuehren', [ContactController::class, 'merge'])->name('contacts.merge');

    // Notizen und Schlagworte (offen seit WP-18), bedient aus dem Posteingang.
    Route::post('kontakte/{contact}/notizen', [NotizController::class, 'store'])->name('contacts.notes.store');
    Route::delete('kontakte/{contact}/notizen/{note}', [NotizController::class, 'destroy'])->name('contacts.notes.destroy');
    Route::post('kontakte/{contact}/schlagworte', [NotizController::class, 'schlagwortStore'])->name('contacts.tags.store');
    Route::delete('kontakte/{contact}/schlagworte/{tag}', [NotizController::class, 'schlagwortDestroy'])->name('contacts.tags.destroy');
    Route::post('zusammenfuehrungen/{merge}/rueckgaengig', [ContactController::class, 'revert'])->name('merges.revert');
});
