<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meldet einen Betreiber nach einer Weile ohne Anfrage ab (WP-34a, C14).
 *
 * **Der Ausgleich fuer den fehlenden zweiten Faktor.** Ein Betreiberkonto
 * reicht quer ueber alle Praxen; ein Rechner, der unbeaufsichtigt
 * angemeldet bleibt, ist dort ein groesseres Risiko als am Empfang einer
 * Praxis. Fuer das Team einer Praxis gilt die Frist deshalb nicht.
 *
 * Die Abmeldung loest das Ereignis Logout aus -- und das beendet eine
 * laufende Impersonation mit.
 */
final class BetreiberLeerlauf
{
    public const SESSION_KEY = 'betreiber_aktiv_at';

    public function __construct(private readonly AuditLogger $protokoll) {}

    public function handle(Request $request, Closure $next): Response
    {
        $benutzer = $request->user();

        if (! $benutzer instanceof User || ! $benutzer->istBetreiber() || ! $request->hasSession()) {
            return $next($request);
        }

        $jetzt = CarbonImmutable::now();
        $zuletzt = $request->session()->get(self::SESSION_KEY);
        $grenze = (int) config('mrs.backoffice.leerlauf_minuten');

        if (is_int($zuletzt) && $jetzt->getTimestamp() - $zuletzt > $grenze * 60) {
            $this->protokoll->record(
                ereignis: AuditEvent::OperatorLoggedOutIdle,
                gegenstand: $benutzer,
                ohneOrganisation: true,
            );

            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('backoffice.anmelden')->withErrors([
                'email' => "Nach {$grenze} Minuten ohne Aktivität abgemeldet. Bitte melden Sie sich erneut an.",
            ]);
        }

        $request->session()->put(self::SESSION_KEY, $jetzt->getTimestamp());

        return $next($request);
    }
}
