<?php

declare(strict_types=1);

use App\Http\Controllers\Werbung\WerbekontoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Werbung (WP-26)
|--------------------------------------------------------------------------
|
| Lesend. Dieses Paket legt bei Meta nichts an und aendert nichts -- das
| gehoert zu WP-27 und zu einer Berechtigung, die wir noch nicht haben.
|
*/

Route::middleware(['auth', 'verified', 'can:campaigns.manage'])->group(function () {
    Route::get('werbung', [WerbekontoController::class, 'index'])->name('werbung.index');

    Route::get('werbung/verbinden', [WerbekontoController::class, 'verbinden'])
        ->name('werbung.verbinden');

    // Der Pfad steht in .env als META_REDIRECT_URI und ist bei Meta
    // hinterlegt. Ihn spaeter zu aendern heisst, jede bestehende Verbindung
    // anzufassen.
    Route::get('oauth/meta/callback', [WerbekontoController::class, 'rueckkehr'])
        ->name('werbung.rueckkehr');

    Route::post('werbung/auswahl', [WerbekontoController::class, 'auswaehlen'])
        ->name('werbung.auswaehlen');

    Route::post('werbung/{werbekonto}/abgleichen', [WerbekontoController::class, 'abgleichen'])
        ->name('werbung.abgleichen');

    // Die Facebook-Seite als Absender der Anzeigen. Von Hand eingetragen:
    // sie zu lesen braeuchte pages_show_list (WP-00).
    Route::patch('werbung/{werbekonto}/seite', [WerbekontoController::class, 'seiteHinterlegen'])
        ->name('werbung.seite');

    Route::delete('werbung/{werbekonto}', [WerbekontoController::class, 'trennen'])
        ->name('werbung.trennen');

    // Schreibend (WP-27). Beide stellen einen Auftrag ein und rufen Meta
    // nicht im Anfragezyklus auf (Entscheidung B2).
    Route::post('werbung/kampagnen', [WerbekontoController::class, 'kampagneAnlegen'])
        ->name('werbung.kampagne.anlegen');

    Route::patch('werbung/kampagnen/{kampagne}', [WerbekontoController::class, 'kampagneAendern'])
        ->name('werbung.kampagne.aendern');
});
