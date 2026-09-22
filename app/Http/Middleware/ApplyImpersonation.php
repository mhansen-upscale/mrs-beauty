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
 * Laeuft **nach** ResolveTenant: fuer einen Super-Admin gibt es keine eigene
 * Organisation, und fuer alle anderen soll die Impersonation die eigene
 * ueberschreiben koennen.
 *
 * Eine abgelaufene Sitzung wird hier beendet und wirkt sofort nicht mehr --
 * nicht erst, wenn ein Aufraeumjob sie anfasst.
 */
final class ApplyImpersonation
{
    public const SESSION_KEY = 'impersonation_session_id';

    public function handle(Request $request, Closure $next): Response
    {
        $benutzer = $request->user();

        if (! $benutzer instanceof User || ! $benutzer->isSuperAdmin()) {
            return $next($request);
        }

        $sitzung = app(Impersonation::class)->laufendeVon($benutzer);

        if (! $sitzung instanceof ImpersonationSession) {
            $request->session()->forget(self::SESSION_KEY);

            return $next($request);
        }

        if ($sitzung->istAbgelaufen()) {
            app(Impersonation::class)->end($sitzung, 'expired');
            $request->session()->forget(self::SESSION_KEY);

            return $next($request);
        }

        $organisation = app(TenantContext::class)->acrossTenants(
            'Organisation einer laufenden Impersonation aufloesen',
            fn (): ?Organization => Organization::query()
                ->whereKey($sitzung->organization_id)
                ->first()
        );

        if ($organisation instanceof Organization) {
            app(TenantContext::class)->set($organisation);
            app(ImpersonationContext::class)->set($sitzung);
        }

        return $next($request);
    }
}
