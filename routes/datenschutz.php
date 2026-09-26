<?php

declare(strict_types=1);

use App\Http\Controllers\Datenschutz\AnhangController;
use App\Http\Controllers\Datenschutz\DatenschutzController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Datenschutz (WP-18)
|--------------------------------------------------------------------------
|
| Aufbewahrungsfristen und Betroffenenrechte. Eine Sache der Organisation,
| nicht des Empfangs: wer eine Frist verlaengert oder einen Loeschlauf scharf
| schaltet, trifft eine Entscheidung, die das ganze Haus betrifft.
|
*/

Route::middleware(['auth', 'verified', 'can:organization.manage'])->group(function () {
    Route::get('datenschutz', [DatenschutzController::class, 'index'])->name('privacy.index');
    Route::patch('datenschutz/fristen/{policy}', [DatenschutzController::class, 'update'])->name('privacy.policies.update');
    Route::post('datenschutz/durchsetzen', [DatenschutzController::class, 'enforce'])->name('privacy.enforce');
});

/*
| Anhaenge ausliefern (offen seit WP-21). **Eine Route fuer alle**, mit der
| Berechtigung dessen, woran der Anhang haengt -- entschieden im Controller,
| nicht an der Route: ein Chat-Anhang gehoert zum Posteingang, Referenz-
| material zum Brand Guide.
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('anhaenge/{attachment}', AnhangController::class)->name('anhang.zeigen');
});
