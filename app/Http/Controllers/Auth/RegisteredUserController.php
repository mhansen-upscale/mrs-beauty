<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\KeyRing;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offene Registrierung -- legt Organisation **und** Inhaberin an.
 *
 * Standardmaessig abgeschaltet (config/mrs.php, registration.self_service).
 * Ein Produkt, das HWG-Pruefungen fuer Aerzte ausspricht, will nicht, dass
 * sich Beliebige eine Praxis anlegen. Der uebliche Weg ins Produkt ist die
 * Einladung.
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        $this->stelleSicherDassOffen();

        return Inertia::render('auth/Register');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $this->stelleSicherDassOffen();

        $validiert = $request->validate([
            'organization' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Organisation, Schluesselsatz und Inhaberin entstehen gemeinsam.
        // Schlaegt ein Schritt fehl, entsteht keiner davon -- eine
        // Organisation ohne Schluesselsatz waere von der ersten Sekunde an
        // unbrauchbar.
        $benutzer = DB::transaction(function () use ($validiert): User {
            $organisation = new Organization;
            $organisation->name = (string) $validiert['organization'];
            $organisation->slug = $this->freierSlug((string) $validiert['organization']);
            $organisation->settings = [];
            $organisation->save();

            app(KeyRing::class)->issue($organisation);

            $benutzer = new User;
            $benutzer->organization_id = $organisation->getKey();
            $benutzer->role = Role::Owner;
            $benutzer->name = (string) $validiert['name'];
            $benutzer->email = (string) $validiert['email'];
            $benutzer->password = Hash::make((string) $validiert['password']);
            $benutzer->save();

            return $benutzer;
        });

        event(new Registered($benutzer));

        Auth::login($benutzer);

        return to_route('dashboard');
    }

    private function stelleSicherDassOffen(): void
    {
        abort_unless((bool) config('mrs.registration.self_service'), 404);
    }

    private function freierSlug(string $name): string
    {
        $basis = Str::slug($name) ?: 'praxis';
        $slug = $basis;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $basis.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
