<?php

declare(strict_types=1);

use App\Http\Controllers\Audit\ImpersonationController;
use App\Http\Controllers\Settings\AgentController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PostfachController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TrackingController;
use App\Http\Controllers\Whitelabel\ErscheinungsbildController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Einstellungen
|--------------------------------------------------------------------------
|
| Hier steht, was die **eigene Person** betrifft -- und die wenigen
| technischen Einstellungen der Praxis, die kein Arbeitsbereich sind: die
| Pixel-ID und das Postfach. Stammdaten, Katalog, Team und Protokoll sind
| Arbeit und stehen in der Hauptnavigation -- siehe routes/stammdaten.php und
| routes/organisation.php.
|
*/

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Die Pixel-ID betrifft nicht die eigene Person, sondern die Praxis --
    // sie steht hier, weil sie eine Einstellung ist und kein Arbeitsbereich.
    Route::middleware('can:organization.manage')->group(function () {
        Route::get('settings/tracking', [TrackingController::class, 'edit'])->name('tracking.edit');
        Route::put('settings/tracking', [TrackingController::class, 'update'])->name('tracking.update');

        // Das Postfach der Praxis (WP-20b): Eingangsadresse, Absender und
        // -- optional -- die Zugangsdaten zum eigenen Mailserver.
        Route::get('settings/postfach', [PostfachController::class, 'edit'])->name('postfach.edit');
        Route::put('settings/postfach', [PostfachController::class, 'update'])->name('postfach.update');
        Route::post('settings/postfach/probe', [PostfachController::class, 'pruefen'])->name('postfach.pruefen');
    });

    // Das Erscheinungsbild der Buchungsseite (WP-07). Eigene Faehigkeit:
    // wer die Pixel-ID pflegt, entscheidet nicht ueber die Marke.
    Route::middleware('can:whitelabel.manage')->group(function () {
        Route::get('settings/erscheinungsbild', [ErscheinungsbildController::class, 'edit'])->name('erscheinungsbild.edit');
        Route::put('settings/erscheinungsbild', [ErscheinungsbildController::class, 'update'])->name('erscheinungsbild.update');
        Route::post('settings/erscheinungsbild/logo', [ErscheinungsbildController::class, 'logo'])->name('erscheinungsbild.logo');
        Route::delete('settings/erscheinungsbild/logo', [ErscheinungsbildController::class, 'logoEntfernen'])->name('erscheinungsbild.logo.entfernen');
    });

    // Der Assistent (WP-23): Not-Aus und Konfidenzschwelle. Eigene
    // Faehigkeit -- wer den Posteingang bedient, entscheidet nicht darueber,
    // ob ein Assistent mitschreibt.
    Route::middleware('can:agent.manage')->group(function () {
        Route::get('settings/assistent', [AgentController::class, 'edit'])->name('agent.edit');
        Route::put('settings/assistent', [AgentController::class, 'update'])->name('agent.update');
    });

    // Impersonation (WP-05). Die Mandantenauswahl gehoert zu WP-34.
    Route::post('impersonation', [ImpersonationController::class, 'store'])->name('impersonation.store');
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');
    Route::post('impersonation/{session}/freigeben', [ImpersonationController::class, 'approve'])
        ->middleware('can:impersonation.approve')
        ->name('impersonation.approve');
});
