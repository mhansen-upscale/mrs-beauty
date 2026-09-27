<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OperatorAbility;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ohne Betreiberrolle kein Backoffice -- und ohne Faehigkeit keine Route.
 *
 * **Eine eigene Mittelschicht und kein Gate** (Ability): Faehigkeiten einer
 * Praxis haengen an Rollen innerhalb dieser Praxis, und der Betreiber gehoert
 * zu keiner. Wer hier hereinkommt, arbeitet ueber Mandantengrenzen hinweg --
 * das ist kein Abstufungs-, sondern ein Grundsatzunterschied (Regel 1).
 *
 * Aufruf als `betreiber` (irgendeine Betreiberrolle) oder
 * `betreiber:<faehigkeit>`, etwa `betreiber:mandanten.sperren` (WP-34a).
 */
final class EnsureBetreiber
{
    public function handle(Request $request, Closure $next, ?string $faehigkeit = null): Response
    {
        $benutzer = $request->user();

        abort_unless($benutzer instanceof User && $benutzer->istBetreiber(), 403);

        if ($faehigkeit !== null) {
            // Eine unbekannte Faehigkeit ist ein Tippfehler in der Route --
            // und der soll laut scheitern, nicht still durchlassen.
            abort_unless($benutzer->betreiberDarf(OperatorAbility::from($faehigkeit)), 403);
        }

        return $next($request);
    }
}
