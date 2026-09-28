<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Abrechnung\Abozugang;
use App\Enums\Ability;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ein Abo, das den Zugang sperrt, sperrt ihn (WP-06, WP-34c).
 *
 * Stripe mahnt mehrfach; bis dahin bleibt alles offen (Entscheidung B9, und
 * `SubscriptionStatus::PastDue`). Gesperrt wird nach der letzten Mahnung
 * (`unpaid`), bei einer Pause (B17), nach der Testphase (B18) und nach einer
 * Kuendigung -- welche davon gilt, weiss Subscription::zugang().
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
     * Seit WP-34c auch die Impersonation: ein Betreiber in einer gesperrten
     * Praxis landete sonst auf `abo.edit`, bekam dort einen 403 -- ihm fehlt
     * `billing.manage` -- und kam nicht einmal mehr hinaus.
     *
     * Seit WP-38 die oeffentlichen Seiten: Das Impressum muss jederzeit
     * erreichbar sein, auch fuer eine Praxis mit offener Rechnung.
     *
     * @var list<string>
     */
    private const OFFEN = [
        '/',
        'impressum',
        'datenschutzerklaerung',
        'demo-anfrage',
        'settings/abo',
        'settings/abo/*',
        'abo-gesperrt',
        'logout',
        'confirm-password',
        'impersonation',
        'impersonation/*',
        'backoffice',
        'backoffice/*',
    ];

    public function __construct(private readonly Abozugang $abozugang) {}

    public function handle(Request $request, Closure $next): Response
    {
        $benutzer = $request->user();

        if ($benutzer === null || $request->is(self::OFFEN)) {
            return $next($request);
        }

        // Ohne Mandanten gibt es kein Abo -- der Betreiber etwa gehoert zu
        // keiner Praxis.
        $praxis = app(TenantContext::class)->current();

        if (! $praxis instanceof Organization) {
            return $next($request);
        }

        // **Nur lesen** und **an einer Stelle** (WP-34c): unbezahlt, pausiert,
        // Testphase abgelaufen, gekuendigt. Eine Praxis ohne Abo-Zeile ist in
        // der Testphase -- gerechnet ab ihrem Anlegen.
        $lage = $this->abozugang->fuer($praxis);

        if (! $lage->sperrtZugang()) {
            return $next($request);
        }

        // Kein 403: die Praxis hat nichts falsch gemacht, sie hat etwas
        // offen. Wer es loesen kann, landet dort, wo es geht; alle anderen
        // lesen, wer es kann.
        if ($benutzer->hasAbility(Ability::ManageBilling)) {
            return redirect()->route('abo.edit')->with('fehler', $lage->hinweis());
        }

        return redirect()->route('abo.gesperrt');
    }
}
