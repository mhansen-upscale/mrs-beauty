<?php

declare(strict_types=1);

use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Team\InvitationController;
use App\Http\Controllers\Team\MemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Organisation: Team und Protokoll
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {
    // Team (WP-04)
    Route::middleware('can:team.manage')->group(function () {
        Route::get('team', [MemberController::class, 'index'])->name('team.index');

        Route::patch('team/{member}', [MemberController::class, 'update'])->name('team.update');
        Route::delete('team/{member}', [MemberController::class, 'deactivate'])->name('team.deactivate');
        Route::put('team/{member}/reaktivieren', [MemberController::class, 'reactivate'])->name('team.reactivate');

        Route::post('team/einladungen', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('team/einladungen/{invitation}/erneut', [InvitationController::class, 'resend'])->name('invitations.resend');
        Route::delete('team/einladungen/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
    });

    // Protokoll (WP-05)
    Route::get('protokoll', [AuditLogController::class, 'index'])
        ->middleware('can:audit.view')
        ->name('audit.index');
});
