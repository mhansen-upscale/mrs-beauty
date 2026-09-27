<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\BetreiberLeerlauf;
use App\Http\Requests\Backoffice\BetreiberLoginRequest;
use Carbon\CarbonImmutable;
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

    public function store(BetreiberLoginRequest $request, AuditLogger $protokoll): RedirectResponse
    {
        $request->authenticate($protokoll);

        $request->session()->regenerate();

        // Die Leerlauffrist beginnt mit der Anmeldung, nicht mit der
        // naechsten Anfrage.
        $request->session()->put(BetreiberLeerlauf::SESSION_KEY, CarbonImmutable::now()->getTimestamp());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
