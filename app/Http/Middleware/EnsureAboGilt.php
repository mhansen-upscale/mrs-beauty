<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nach der letzten Mahnung ist Schluss.
 *
 * Stripe mahnt mehrfach; bis dahin bleibt alles offen (Entscheidung B9, und
 * `SubscriptionStatus::PastDue`). Erst wenn Stripe aufgibt -- Zustand
 * `unpaid` --, sperrt das Produkt den Zugang.
 *
 * **Drei Dinge bleiben trotzdem erreichbar**, und jedes aus einem eigenen
 * Grund:
 *
 * - *Das Abo selbst.* Eine Sperre, aus der man nicht herauskommt, ohne
 *   hineinzukommen, ist eine Falle.
 * - *Abmelden.* Wer gesperrt ist, soll den Rechner verlassen koennen.
 * - *Der Betreiber* (WP-34). Er sperrt und entsperrt; sein Zugang haengt
 *   nicht am Abo einer Praxis.
 *
 * **Was weiterlaeuft, ohne dass jemand hineinkommt:** die Erinnerungen an
 * bereits gebuchte Termine. Eine Patientin, die einen Termin hat, soll ihn
 * nicht verpassen, weil die Praxis eine Rechnung nicht bezahlt hat.
 */
final class EnsureAboGilt
{
    /**
     * Routen, die trotz Sperre erreichbar bleiben.
     *
     * @var list<string>
     */
    private const OFFEN = [
        'settings/abo',
        'settings/abo/*',
        'logout',
        'backoffice',
        'backoffice/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || $request->is(self::OFFEN)) {
            return $next($request);
        }

        // Ohne Mandanten gibt es kein Abo -- der Betreiber etwa gehoert zu
        // keiner Praxis.
        if (! app(TenantContext::class)->current() instanceof Organization) {
            return $next($request);
        }

        // **Nur lesen.** Kontingente::abo() legt eines an, wenn keines da
        // ist -- ein Schreibvorgang in jeder Anfrage waere hier falsch, und
        // eine Praxis ohne Abo ist in der Testphase, nicht gesperrt.
        $abo = Subscription::query()->first();

        if (! $abo instanceof Subscription || ! $abo->status->sperrtZugang()) {
            return $next($request);
        }

        // Kein 403: die Praxis hat nichts falsch gemacht, sie hat etwas
        // offen. Der Weg dahin steht auf der Seite.
        return redirect()->route('abo.edit')->with(
            'fehler',
            'Der Zugang ist gesperrt, weil die Zahlung ausgeblieben ist. '
            .'Sobald sie eingeht, steht alles wieder offen — Ihre Daten bleiben unverändert.',
        );
    }
}
