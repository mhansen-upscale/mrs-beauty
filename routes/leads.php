<?php

declare(strict_types=1);

use App\Http\Controllers\Leads\LeadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Anfragen (WP-17)
|--------------------------------------------------------------------------
|
| `leads` und `contacts` sind getrennt (Entscheidung D3): dieselbe Person
| fragt im Maerz nach Botox und im Oktober nach Hyaluron -- zwei Anfragen mit
| zwei Quellen und zwei Ergebnissen.
|
| Dieselbe Faehigkeit wie die Kontakte: wer Kontakte pflegt, pflegt auch die
| Anfragen dazu. Eine eigene Berechtigung waere eine Festlegung, die in
| docs/entscheidungen.md nicht steht.
|
*/

Route::middleware(['auth', 'verified', 'can:contacts.manage'])->group(function () {
    Route::get('anfragen', [LeadController::class, 'index'])->name('leads.index');
    Route::post('anfragen', [LeadController::class, 'store'])->name('leads.store');
    Route::post('anfragen/{lead}/reaktion', [LeadController::class, 'respond'])->name('leads.respond');
    Route::post('anfragen/{lead}/verloren', [LeadController::class, 'lose'])->name('leads.lose');
});
