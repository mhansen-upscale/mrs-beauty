<?php

declare(strict_types=1);

use App\Http\Controllers\Oeffentlich\DemoanfrageController;
use App\Http\Controllers\Oeffentlich\RechtstexteController;
use App\Http\Controllers\Oeffentlich\StartseiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Oeffentliche Seiten des Betreibers (WP-38)
|--------------------------------------------------------------------------
|
| Startseite, Impressum und Datenschutzerklaerung -- ohne Anmeldung und ohne
| Mandanten. **Keine `guest`-Mittelschicht:** Wer angemeldet ist, sieht die
| Seiten auch, das Impressum muss jederzeit erreichbar sein.
|
| `/datenschutz` ist die Seite der Praxis (WP-18, `privacy.index`). Die
| Erklaerung des Betreibers steht deshalb unter `/datenschutzerklaerung`.
|
*/

// Der Name bleibt `home`: das Logo der Anmeldeseiten zeigt hierher.
Route::get('/', StartseiteController::class)->name('home');

Route::get('impressum', [RechtstexteController::class, 'impressum'])->name('impressum');
Route::get('datenschutzerklaerung', [RechtstexteController::class, 'datenschutzerklaerung'])->name('datenschutzerklaerung');

// Gedrosselt wie die Buchungsseite: ein oeffentliches Formular, das
// Datensaetze anlegt und Mails ausloest, ist sonst ein Werkzeug, um beides zu
// fluten.
Route::post('demo-anfrage', [DemoanfrageController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('demoanfrage.senden');
