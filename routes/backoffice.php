<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ZweiFaktorAnmeldungController;
use App\Http\Controllers\Backoffice\BackofficeController;
use App\Http\Controllers\Backoffice\BetreiberAnmeldungController;
use App\Http\Controllers\Backoffice\BetreiberController;
use App\Http\Controllers\Backoffice\BetreiberprotokollController;
use App\Http\Controllers\Backoffice\PaketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Backoffice des Betreibers (WP-34, WP-34a)
|--------------------------------------------------------------------------
|
| **Eine eigene Mittelschicht, keine Faehigkeit einer Praxis.** Faehigkeiten
| haengen an Rollen innerhalb einer Praxis; der Betreiber gehoert zu keiner.
| Wer hier hereinkommt, arbeitet ueber Mandantengrenzen hinweg -- das ist
| kein Abstufungs-, sondern ein Grundsatzunterschied (Regel 1).
|
| **Jede Route traegt ihre Faehigkeit** (`betreiber:<faehigkeit>`, C14).
| tests/Feature/Backoffice/BetreiberrollenTest.php prueft das als Tabelle
| und scheitert an jeder Route, die dort fehlt.
|
| Gezeigt werden Zustaende und Zahlen. Wer in eine Praxis hineinsehen muss,
| geht ueber die Impersonation aus WP-05.
|
*/

// Der eigene Eingang (C14). Vor der Gruppe: `backoffice/{organisation}`
// passte sonst auch auf `backoffice/anmelden`.
Route::middleware('guest')->group(function () {
    Route::get('backoffice/anmelden', [BetreiberAnmeldungController::class, 'create'])->name('backoffice.anmelden');
    Route::post('backoffice/anmelden', [BetreiberAnmeldungController::class, 'store'])->name('backoffice.anmelden.senden');

    // Der Code-Schritt (WP-35) -- derselbe Controller wie an /login, aber
    // eine ausstehende Anmeldung der Praxis gilt hier nicht.
    Route::get('backoffice/anmelden/code', [ZweiFaktorAnmeldungController::class, 'show'])
        ->defaults('eingang', 'betreiber')
        ->name('backoffice.anmelden.code');
    Route::post('backoffice/anmelden/code', [ZweiFaktorAnmeldungController::class, 'pruefe'])
        ->defaults('eingang', 'betreiber')
        ->middleware('throttle:30,1')
        ->name('backoffice.anmelden.code.pruefen');
    Route::post('backoffice/anmelden/code/erneut', [ZweiFaktorAnmeldungController::class, 'erneut'])
        ->defaults('eingang', 'betreiber')
        ->middleware('throttle:10,1')
        ->name('backoffice.anmelden.code.erneut');
    Route::delete('backoffice/anmelden/code', [ZweiFaktorAnmeldungController::class, 'abbrechen'])
        ->defaults('eingang', 'betreiber')
        ->name('backoffice.anmelden.code.abbrechen');
});

Route::middleware(['auth', 'verified', 'betreiber'])->prefix('backoffice')->group(function () {
    Route::get('/', [BackofficeController::class, 'index'])
        ->middleware('betreiber:mandanten.sehen')
        ->name('backoffice.index');

    Route::middleware('betreiber:betreiber.verwalten')->group(function () {
        Route::get('betreiber', [BetreiberController::class, 'index'])->name('backoffice.betreiber.index');
        Route::post('betreiber', [BetreiberController::class, 'store'])->name('backoffice.betreiber.store');
        Route::patch('betreiber/{betreiber}/rolle', [BetreiberController::class, 'rolle'])->name('backoffice.betreiber.rolle');
        Route::post('betreiber/{betreiber}/deaktivieren', [BetreiberController::class, 'deaktivieren'])->name('backoffice.betreiber.deaktivieren');
        Route::post('betreiber/{betreiber}/reaktivieren', [BetreiberController::class, 'reaktivieren'])->name('backoffice.betreiber.reaktivieren');
        Route::post('betreiber/{betreiber}/zwei-faktor-zuruecksetzen', [BetreiberController::class, 'zweiFaktorZuruecksetzen'])->name('backoffice.betreiber.zwei-faktor');
        Route::delete('betreiber/{betreiber}', [BetreiberController::class, 'loeschen'])->name('backoffice.betreiber.loeschen');
    });

    // Das Paket in Fassungen (WP-06b, B20): Speichern legt eine neue an.
    Route::middleware('betreiber:paket.verwalten')->group(function () {
        Route::get('paket', [PaketController::class, 'index'])->name('backoffice.paket');
        Route::post('paket', [PaketController::class, 'store'])->name('backoffice.paket.store');
    });

    Route::get('protokoll', [BetreiberprotokollController::class, 'index'])
        ->middleware('betreiber:protokoll.sehen')
        ->name('backoffice.protokoll');

    // **Nur UUIDs** -- sonst schluckte der Platzhalter jede feste Route, die
    // nach ihm in diese Gruppe kommt.
    Route::get('{organisation}', [BackofficeController::class, 'show'])
        ->whereUuid('organisation')
        ->middleware('betreiber:mandanten.sehen')
        ->name('backoffice.show');

    Route::post('{organisation}/sperren', [BackofficeController::class, 'sperren'])
        ->whereUuid('organisation')
        ->middleware('betreiber:mandanten.sperren')
        ->name('backoffice.sperren');

    Route::post('{organisation}/entsperren', [BackofficeController::class, 'entsperren'])
        ->whereUuid('organisation')
        ->middleware('betreiber:mandanten.sperren')
        ->name('backoffice.entsperren');

    Route::post('{organisation}/gutschrift', [BackofficeController::class, 'gutschreiben'])
        ->whereUuid('organisation')
        ->middleware('betreiber:kontingent.gutschreiben')
        ->name('backoffice.gutschrift');

    // Abo-Eingriffe (WP-34c, B17): als Auftrag bei Stripe, im Testbetrieb
    // sofort lokal. Die Testphase lebt nur bei uns und hat ihre eigene
    // Faehigkeit -- Customer Success darf sie, das Abo nicht.
    Route::post('{organisation}/abo', [BackofficeController::class, 'abo'])
        ->whereUuid('organisation')
        ->middleware('betreiber:abo.eingreifen')
        ->name('backoffice.abo');

    Route::post('{organisation}/testphase', [BackofficeController::class, 'testphase'])
        ->whereUuid('organisation')
        ->middleware('betreiber:testphase.verlaengern')
        ->name('backoffice.testphase');
});
