<?php

declare(strict_types=1);

use App\Http\Controllers\Audit\ImpersonationController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Einstellungen
|--------------------------------------------------------------------------
|
| Hier steht nur noch, was die **eigene Person** betrifft. Stammdaten,
| Katalog, Team und Protokoll sind Arbeitsbereiche und stehen in der
| Hauptnavigation -- siehe routes/stammdaten.php und routes/organisation.php.
|
*/

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Impersonation (WP-05). Die Mandantenauswahl gehoert zu WP-34.
    Route::post('impersonation', [ImpersonationController::class, 'store'])->name('impersonation.store');
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');
    Route::post('impersonation/{session}/freigeben', [ImpersonationController::class, 'approve'])
        ->middleware('can:impersonation.approve')
        ->name('impersonation.approve');
});
