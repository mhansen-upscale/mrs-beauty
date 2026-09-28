<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Anmeldeeingang;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\ZweiFaktor\AusstehendeAnmeldung;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Die Anmeldeseite.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            // Die Route gibt es immer, der Controller weist sie bei
            // geschlossener Registrierung mit 404 ab. Ein Hinweis auf eine
            // Seite, die 404 liefert, gehoert nicht auf die Anmeldung --
            // deshalb der Schalter selbst und nicht Route::has().
            'canRegister' => (bool) config('mrs.registration.self_service'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Nach dem Passwort: angemeldet oder weiter zum Code (WP-35).
     */
    public function store(LoginRequest $request, AusstehendeAnmeldung $anmeldung): RedirectResponse
    {
        $benutzer = $request->authenticate();

        return $anmeldung->beginne($request, $benutzer, Anmeldeeingang::Praxis, $request->boolean('remember'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Vor dem Abmelden gefragt -- danach gibt es keinen Benutzer mehr.
        $benutzer = $request->user();
        $betreiber = $benutzer instanceof User && $benutzer->istBetreiber();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Der Betreiber hat seinen eigenen Eingang (WP-34a).
        return $betreiber ? redirect()->route('backoffice.anmelden') : redirect('/');
    }
}
