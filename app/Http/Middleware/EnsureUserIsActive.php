<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wirft eine deaktivierte Person aus ihrer laufenden Sitzung.
 *
 * Eine Deaktivierung, die nur die Anmeldung sperrt, wirkt nicht: die
 * bestehende Sitzung laeuft sonst weiter, bis sie ablaeuft. Bei jemandem, der
 * die Praxis im Streit verlassen hat, sind das zwei Stunden zu viel.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $benutzer = $request->user();

        if ($benutzer instanceof User && $benutzer->isDeactivated()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Dieser Zugang wurde deaktiviert.',
            ]);
        }

        return $next($request);
    }
}
