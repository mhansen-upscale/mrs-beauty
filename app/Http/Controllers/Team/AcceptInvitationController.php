<?php

declare(strict_types=1);

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Annahme einer Einladung.
 *
 * Laeuft ohne Anmeldung und **ohne Mandantenkontext** -- wer die Einladung
 * oeffnet, gehoert noch zu keiner Organisation. Die Einladung wird deshalb
 * ausdruecklich quer zu den Mandanten gesucht, ueber den Hash des Merkmals.
 */
final class AcceptInvitationController extends Controller
{
    public function show(string $token): Response
    {
        $einladung = $this->finde($token);

        if (! $einladung instanceof Invitation || ! $einladung->istOffen()) {
            return Inertia::render('auth/InvitationInvalid', [
                'reason' => $einladung?->grundDerUngueltigkeit()
                    ?? 'Diese Einladung ist unbekannt.',
            ]);
        }

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'email' => $einladung->email,
            'role' => $einladung->role->label(),
            'organization' => $this->organisationVon($einladung)?->name,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $einladung = $this->finde($token);

        abort_unless($einladung instanceof Invitation && $einladung->istOffen(), 404);

        $validiert = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $benutzer = DB::transaction(function () use ($einladung, $validiert): User {
            $benutzer = new User;
            $benutzer->organization_id = $einladung->organization_id;
            $benutzer->role = $einladung->role;
            $benutzer->name = (string) $validiert['name'];
            $benutzer->email = $einladung->email;
            $benutzer->password = Hash::make((string) $validiert['password']);

            // Der Weg ueber das Postfach ist der Nachweis. Eine zweite
            // Bestaetigungsmail waere eine Zumutung ohne Erkenntnisgewinn.
            $benutzer->email_verified_at = now();
            $benutzer->save();

            app(TenantContext::class)->acrossTenants(
                'Einladung annehmen: der Gast gehoert noch zu keiner Organisation',
                function () use ($einladung): void {
                    $einladung->accepted_at = now();
                    $einladung->save();
                }
            );

            return $benutzer;
        });

        event(new Registered($benutzer));

        Auth::login($benutzer);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function finde(string $token): ?Invitation
    {
        return app(TenantContext::class)->acrossTenants(
            'Einladung ueber ihr Merkmal suchen',
            fn (): ?Invitation => Invitation::query()
                ->where('token_hash', Invitation::hashe($token))
                ->first()
        );
    }

    private function organisationVon(Invitation $einladung): ?Organization
    {
        return Organization::query()->whereKey($einladung->organization_id)->first();
    }
}
