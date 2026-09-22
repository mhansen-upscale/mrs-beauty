<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ohne Kennzeichen kein Backoffice.
 *
 * **Eine eigene Mittelschicht und keine Faehigkeit** (Ability): Faehigkeiten
 * haengen an Rollen innerhalb einer Praxis, und der Betreiber gehoert zu
 * keiner. Wer hier hereinkommt, arbeitet ueber Mandantengrenzen hinweg --
 * das ist kein Abstufungs-, sondern ein Grundsatzunterschied (Regel 1).
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $benutzer = $request->user();

        abort_unless($benutzer instanceof User && $benutzer->isSuperAdmin(), 403);

        return $next($request);
    }
}
