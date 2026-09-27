<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wirft das Team einer gesperrten Praxis aus seiner laufenden Sitzung.
 *
 * WP-34 AK 8 ("Eine gesperrte Praxis kommt nicht mehr hinein") stand als
 * erfuellt im Briefing -- `suspended_at` las aber allein die oeffentliche
 * Buchungsseite. Eine Sperre, die nur das naechste Anmelden verhindert,
 * wirkt nicht: die Sitzung laeuft sonst weiter, bis sie ablaeuft
 * (dasselbe Muster wie EnsureUserIsActive).
 *
 * **Geprueft wird die eigene Organisation des Benutzers**, nicht der
 * geltende Mandant. Sonst flogen der Betreiber hinaus, der eine gesperrte
 * Praxis impersoniert -- also genau der, der ihr gerade helfen soll.
 *
 * Laeuft nach ResolveTenant, das die Organisation schon geladen hat, und vor
 * ApplyImpersonation.
 */
final class EnsurePraxisNichtGesperrt
{
    public const MELDUNG = 'Der Zugang dieser Praxis ist gesperrt. Bitte wenden Sie sich an den Support.';

    public function handle(Request $request, Closure $next): Response
    {
        $benutzer = $request->user();

        if (! $benutzer instanceof User || $benutzer->istBetreiber()) {
            return $next($request);
        }

        $praxis = $benutzer->organization;

        if (! $praxis instanceof Organization || $praxis->suspended_at === null) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        app(TenantContext::class)->forget();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => self::MELDUNG]);
    }
}
