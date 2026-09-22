<?php

declare(strict_types=1);

use App\Http\Controllers\Kanaele\InboxController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Posteingang (WP-21)
|--------------------------------------------------------------------------
|
| **Zwei Faehigkeiten, nicht eine**: `inbox.view` sieht, `inbox.reply`
| antwortet. Eine Aushilfe darf lesen, ohne im Namen der Praxis zu schreiben
| -- eine Nachricht an eine Patientin ist nicht zurueckzuholen.
|
| Die Faehigkeit wird zusaetzlich im Controller geprueft: das Middleware
| allein liesse eine spaeter vergessene Route offen.
|
*/

Route::middleware(['auth', 'verified', 'can:inbox.view'])->group(function () {
    Route::get('posteingang', [InboxController::class, 'index'])->name('inbox.index');

    Route::post('posteingang/{conversation}/antwort', [InboxController::class, 'reply'])->name('inbox.reply');
    Route::post('posteingang/{conversation}/template', [InboxController::class, 'template'])->name('inbox.template');
    Route::post('posteingang/{conversation}/schliessen', [InboxController::class, 'close'])->name('inbox.close');
    Route::post('posteingang/{conversation}/oeffnen', [InboxController::class, 'reopen'])->name('inbox.reopen');
    Route::post('posteingang/{conversation}/zuordnen', [InboxController::class, 'assign'])->name('inbox.assign');

    // Der Modus des Agenten je Konversation (WP-22, Entscheidung G8). `auto`
    // bleibt bis WP-24 gesperrt -- geprueft im Controller, nicht nur in der
    // Oberflaeche.
    Route::post('posteingang/{conversation}/agent', [InboxController::class, 'agentModus'])->name('inbox.agent');
    Route::post('posteingang/{conversation}/agent/fortsetzen', [InboxController::class, 'agentFortsetzen'])->name('inbox.agent.fortsetzen');
});
