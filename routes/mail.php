<?php

declare(strict_types=1);

use App\Http\Controllers\Mail\PlattformlogoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Was Mailprogramme laden (WP-37)
|--------------------------------------------------------------------------
|
| Ohne Anmeldung: ein Mailprogramm hat keine Sitzung. Nur das Logo der
| Produktmails -- das Logo einer Praxis liefert die Buchungsseite aus.
|
*/

Route::get('mail/logo/{fassung}', PlattformlogoController::class)
    ->whereNumber('fassung')
    ->middleware('throttle:120,1')
    ->name('mail.logo');
