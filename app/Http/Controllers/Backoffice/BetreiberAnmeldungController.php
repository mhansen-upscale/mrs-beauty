<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Audit\AuditLogger;
use App\Enums\Anmeldeeingang;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\BetreiberLoginRequest;
use App\ZweiFaktor\AusstehendeAnmeldung;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der eigene Eingang des Betreibers: `/backoffice/anmelden` (WP-34a, C14).
 *
 * **Derselbe Guard, eine eigene Seite.** Dahinter liegen weiterhin `users` und
 * `web` -- ein zweiter Guard wuerde die Impersonation aus WP-05 zerlegen, die
 * damit rechnet, dass der Betreiber ein User ist. Getrennt ist, wer wo
 * hineinkommt: hier nur Betreiber, an `/login` nur Praxen.
 */
final class BetreiberAnmeldungController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('backoffice/Anmelden', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            'leerlaufMinuten' => (int) config('mrs.backoffice.leerlauf_minuten'),
        ]);
    }

    /**
     * Nach dem Passwort: angemeldet oder weiter zum Code (WP-35). Protokoll
     * und Leerlauffrist setzt AusstehendeAnmeldung::schliesseAb() -- erst,
     * wenn die Person wirklich angemeldet ist. Nie dauerhaft (C14).
     */
    public function store(BetreiberLoginRequest $request, AuditLogger $protokoll, AusstehendeAnmeldung $anmeldung): RedirectResponse
    {
        $benutzer = $request->authenticate($protokoll);

        return $anmeldung->beginne($request, $benutzer, Anmeldeeingang::Betreiber, merken: false);
    }
}
