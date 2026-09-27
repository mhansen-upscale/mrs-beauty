<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\Impersonation;
use App\Audit\ImpersonationContext;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aktiviert eine laufende Impersonation fuer diese Anfrage.
 *
 * Laeuft **nach** ResolveTenant: fuer einen Betreiber gibt es keine eigene
 * Organisation, und fuer alle anderen soll die Impersonation die eigene
 * ueberschreiben koennen.
 *
 * Eine abgelaufene Sitzung wird hier beendet und wirkt sofort nicht mehr --
 * nicht erst, wenn ein Aufraeumjob sie anfasst.
 *
 * **Mandantengebunden gesucht, nicht ueber die Grenze** (WP-34a). Bis hier
 * lief jede Anfrage eines Betreibers zweimal durch `acrossTenants()` -- ein
 * Querzugriff im Protokoll je Seitenaufruf, und die echten gingen darin
 * unter. Die Sitzung legt jetzt beim Start auch die Praxis ab: die
 * Organisation ist kein TenantModel und liest sich ohne Ausnahme, die
 * Sitzung dann im Mandanten ueber `runAs()`.
 *
 * Und die Impersonation haengt damit an **dieser** Browsersitzung, nicht an
 * jeder, die derselbe Betreiber irgendwo offen hat.
 */
final class ApplyImpersonation
{
    public const SESSION_KEY = 'impersonation_session_id';

    public const ORGANISATION_KEY = 'impersonation_organization_id';

    public function handle(Request $request, Closure $next): Response
    {
        $benutzer = $request->user();

        if (! $benutzer instanceof User || ! $benutzer->istBetreiber() || ! $request->hasSession()) {
            return $next($request);
        }

        $sitzungskennung = $request->session()->get(self::SESSION_KEY);
        $praxiskennung = $request->session()->get(self::ORGANISATION_KEY);

        if (! is_string($sitzungskennung) || ! is_string($praxiskennung)) {
            return $this->ohne($request, $next);
        }

        $praxis = Organization::query()->whereUuid($praxiskennung)->first();

        if (! $praxis instanceof Organization) {
            return $this->ohne($request, $next);
        }

        $mandant = app(TenantContext::class);

        $sitzung = $mandant->runAs($praxis, fn (): ?ImpersonationSession => ImpersonationSession::query()
            ->whereUuid($sitzungskennung)
            ->where('impersonator_user_id', $benutzer->getKey())
            ->whereNull('ended_at')
            ->first());

        if (! $sitzung instanceof ImpersonationSession) {
            return $this->ohne($request, $next);
        }

        if ($sitzung->istAbgelaufen()) {
            app(Impersonation::class)->end($sitzung, 'expired');

            return $this->ohne($request, $next);
        }

        $mandant->set($praxis);
        app(ImpersonationContext::class)->set($sitzung);

        return $next($request);
    }

    /** Ohne Impersonation weiter -- und die Schluessel vergessen, die nicht mehr tragen. */
    private function ohne(Request $request, Closure $next): Response
    {
        $request->session()->forget([self::SESSION_KEY, self::ORGANISATION_KEY]);

        return $next($request);
    }
}
