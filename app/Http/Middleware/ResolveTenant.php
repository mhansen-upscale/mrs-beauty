<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loest den Mandanten aus dem angemeldeten Benutzer auf.
 *
 * Laeuft nach dem Auth-Middleware. Ist niemand angemeldet oder gehoert der
 * Benutzer zu keiner Organisation (Super-Admin, WP-34), bleibt der Kontext
 * leer -- und jeder Zugriff auf Mandantendaten wirft dann, statt still nichts
 * zu liefern.
 */
final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $benutzer = $request->user();

        if ($benutzer instanceof User) {
            $organisation = $benutzer->organization;

            if ($organisation instanceof Organization) {
                app(TenantContext::class)->set($organisation);
            }
        }

        return $next($request);
    }
}
