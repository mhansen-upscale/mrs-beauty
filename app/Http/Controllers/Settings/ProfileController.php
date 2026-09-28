<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        assert($user instanceof User);

        // Ein Betreiberkonto loescht ein Super-Admin unter Betreiberkonten
        // (28.09.2026) -- hier loeschte sich sonst auch der letzte, und das
        // Betreiberprotokoll verloere die Handlungen der Person.
        if ($user->istBetreiber()) {
            throw ValidationException::withMessages([
                'password' => 'Ein Betreiberkonto löscht ein Super-Admin unter Betreiberkonten.',
            ]);
        }

        // Die letzte Inhaberin bleibt (WP-04), auch gegen sich selbst -- sonst
        // sperrt sich die Praxis aus. Dieselbe Sperre wie beim Deaktivieren
        // in Team\MemberController.
        if ($user->istLetzteInhaberin()) {
            throw ValidationException::withMessages([
                'password' => 'Die letzte Inhaberin kann ihr Konto nicht löschen. Ernennen Sie zuerst eine weitere Inhaberin.',
            ]);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
