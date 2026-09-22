<?php

declare(strict_types=1);

use App\Http\Controllers\Betrieb\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/buchung.php';
require __DIR__.'/termine.php';
require __DIR__.'/stammdaten.php';
require __DIR__.'/kalender.php';
require __DIR__.'/kontakte.php';
require __DIR__.'/leads.php';
require __DIR__.'/warteliste.php';
require __DIR__.'/datenschutz.php';
require __DIR__.'/kanaele.php';
require __DIR__.'/inbox.php';
require __DIR__.'/werbung.php';
require __DIR__.'/marke.php';
require __DIR__.'/auswertung.php';
require __DIR__.'/hwg.php';
require __DIR__.'/anzeigen.php';
require __DIR__.'/organisation.php';
require __DIR__.'/backoffice.php';
require __DIR__.'/abrechnung.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
