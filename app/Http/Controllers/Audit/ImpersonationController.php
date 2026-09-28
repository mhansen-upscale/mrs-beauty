<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Audit\Impersonation;
use App\Audit\ImpersonationContext;
use App\Audit\Supportfreigabe;
use App\Enums\Ability;
use App\Enums\OperatorAbility;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ApplyImpersonation;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\User;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Start und Ende einer Impersonation.
 *
 * Die Oberflaeche zur Mandantenauswahl gehoert zum Super-Admin-Backoffice
 * (WP-34). Hier liegt nur die Mechanik.
 */
final class ImpersonationController extends Controller
{
    public function store(Request $request, Impersonation $impersonation, Supportfreigabe $freigabe): RedirectResponse
    {
        $superAdmin = $request->user();

        // Die Route verlangt `betreiber:support.zugriff` schon -- hier steht
        // es fuer PHPStan und fuer den Fall, dass jemand die Route umhaengt.
        abort_unless($superAdmin instanceof User && $superAdmin->betreiberDarf(OperatorAbility::SupportZugriff), 403);

        $validiert = $request->validate([
            'organization' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            // Die PIN der Praxis, wenn die Inhaberin sie schon genannt hat:
            // Start und Freigabe in einem Schritt (WP-34b).
            'pin' => ['nullable', 'string', 'max:32'],
        ]);

        $organisation = app(TenantContext::class)->acrossTenants(
            'Organisation fuer eine Impersonation aufloesen',
            fn (): ?Organization => Organization::query()
                ->whereKey(Uuid::toBinary((string) $validiert['organization']))
                ->first()
        );

        if (! $organisation instanceof Organization) {
            throw ValidationException::withMessages([
                'organization' => 'Diese Organisation gibt es nicht.',
            ]);
        }

        $sitzung = $impersonation->start($superAdmin, $organisation, (string) $validiert['reason']);

        // **Sitzung und Praxis**: ApplyImpersonation sucht die Sitzung damit
        // im Mandanten, statt bei jeder Anfrage quer zu lesen (WP-34a).
        $request->session()->put(ApplyImpersonation::SESSION_KEY, $sitzung->uuid);
        $request->session()->put(ApplyImpersonation::ORGANISATION_KEY, $organisation->uuid);

        $pin = trim((string) ($validiert['pin'] ?? ''));

        if ($pin !== '') {
            // Scheitert die PIN, laeuft die Sitzung maskiert weiter -- wie
            // ohne PIN. Der Fehler steht im Banner, dort laesst sie sich
            // erneut eingeben.
            try {
                $freigabe->loese($sitzung, $superAdmin, $pin);
            } catch (\RuntimeException $fehler) {
                return redirect()->route('dashboard')->withErrors(['pin' => $fehler->getMessage()]);
            }
        }

        return redirect()->route('dashboard');
    }

    /**
     * Die PIN der Praxis in der laufenden, maskierten Sitzung eingeben
     * (WP-34b). Die Meldung wiederholt die PIN nie (C5).
     */
    public function pin(Request $request, string $session, Supportfreigabe $freigabe): RedirectResponse
    {
        $betreiber = $request->user();
        abort_unless($betreiber instanceof User && $betreiber->betreiberDarf(OperatorAbility::SupportZugriff), 403);

        // **Erst die Faehigkeit, dann die Sitzung.** Route-Model-Binding liefe
        // vor der Betreiberpruefung und ohne Mandanten -- Finanzen bekaeme
        // dann keinen 403, sondern einen Fehler. Den Mandanten setzt
        // ApplyImpersonation aus der laufenden Sitzung.
        $sitzung = app(ImpersonationContext::class)->current();
        abort_unless($sitzung instanceof ImpersonationSession && $sitzung->uuid === $session, 404);

        $validiert = $request->validate(['pin' => ['required', 'string', 'max:32']]);

        try {
            $freigabe->loese($sitzung, $betreiber, (string) $validiert['pin']);
        } catch (\RuntimeException $fehler) {
            throw ValidationException::withMessages(['pin' => $fehler->getMessage()]);
        }

        return back()->with('erfolg', 'Die Praxis hat den Vollzugriff freigegeben.');
    }

    /**
     * Die Inhaberin beendet den Zugriff des Supports (WP-34b). „Sichtbar fuer
     * beide Seiten" heisst: Sie sieht ihn, und sie kann ihn beenden.
     */
    public function beenden(ImpersonationSession $session, Impersonation $impersonation): RedirectResponse
    {
        Gate::authorize(Ability::ApproveImpersonation->value);

        $impersonation->end($session, 'ended_by_tenant');

        return back()->with('erfolg', 'Der Zugriff des Supports ist beendet.');
    }

    public function destroy(Request $request, Impersonation $impersonation): RedirectResponse
    {
        $superAdmin = $request->user();

        abort_unless($superAdmin instanceof User && $superAdmin->istBetreiber(), 403);

        $sitzung = $impersonation->laufendeVon($superAdmin);

        if ($sitzung instanceof ImpersonationSession) {
            $impersonation->end($sitzung, 'manual');
        }

        $request->session()->forget([ApplyImpersonation::SESSION_KEY, ApplyImpersonation::ORGANISATION_KEY]);

        return redirect()->route('dashboard');
    }

    /**
     * Freigabe des Vollzugriffs durch eine Inhaberin des betroffenen Mandanten.
     */
    public function approve(
        Request $request,
        ImpersonationSession $session,
        Impersonation $impersonation,
    ): RedirectResponse {
        Gate::authorize(Ability::ApproveImpersonation->value);

        $freigebende = $request->user();
        abort_unless($freigebende instanceof User, 403);

        try {
            $impersonation->approve($session, $freigebende);
        } catch (\RuntimeException $fehler) {
            throw ValidationException::withMessages(['session' => $fehler->getMessage()]);
        }

        return back();
    }
}
