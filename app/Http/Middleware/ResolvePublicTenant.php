<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loest den Mandanten fuer die oeffentliche Buchungsseite auf -- aus dem Slug
 * in der URL und **nur** von dort.
 *
 * Das ist die gefaehrlichste Stelle des Projekts: eine oeffentliche,
 * unangemeldete Route, die einen Mandanten setzt. Jede zweite Quelle -- ein
 * Formularfeld, ein Header, ein Cookie -- waere ein zweiter Angriffspunkt.
 * Deshalb gibt es genau diese eine.
 *
 * Alles Weitere erledigt der Global Scope: jede ID, die von aussen
 * hereinkommt, wird innerhalb des hier gesetzten Mandanten gesucht. Eine
 * Terminart einer fremden Praxis findet sich damit nicht, auch nicht mit
 * gueltiger UUID.
 */
final class ResolvePublicTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('praxis');

        abort_unless(is_string($slug) && $slug !== '', 404);

        $organisation = Organization::query()
            ->where('slug', $slug)
            // Eine gesperrte Praxis hat keine Buchungsseite. Sie soll nicht
            // weiter Termine einsammeln, die niemand bearbeitet.
            ->whereNull('suspended_at')
            ->first();

        abort_unless($organisation instanceof Organization, 404);

        app(TenantContext::class)->set($organisation);

        return $next($request);
    }
}
