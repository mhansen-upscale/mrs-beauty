<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Middleware\EnsurePraxisNichtGesperrt;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Prueft die Zugangsdaten und gibt die Person zurueck -- **ohne sie
     * anzumelden**. Das tut AusstehendeAnmeldung, sofort oder nach dem
     * zweiten Faktor (WP-35).
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        // **Erst pruefen, dann anmelden.** Wer mit Auth::attempt anmeldet und
        // danach wieder abmeldet, loest Login und Logout fuer jemanden aus,
        // der gar nicht hinein darf.
        $benutzer = Auth::validate($this->only('email', 'password'))
            ? Auth::getLastAttempted()
            : null;

        // **Betreiber melden sich an ihrem eigenen Eingang an** (WP-34a, C14)
        // -- und hier mit derselben Meldung wie bei falschem Passwort. Die
        // Abweisung kommt erst nach der Pruefung: vorher verriete sie, welche
        // Adressen Betreiberkonten sind.
        if (! $benutzer instanceof User || $benutzer->istBetreiber()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // **Eine gesperrte Praxis kommt nicht wieder hinein** (WP-34 AK 8).
        // EnsurePraxisNichtGesperrt wirft laufende Sitzungen hinaus; hier
        // entsteht gar nicht erst eine.
        if ($benutzer->organization?->suspended_at !== null) {
            throw ValidationException::withMessages([
                'email' => EnsurePraxisNichtGesperrt::MELDUNG,
            ]);
        }

        // **Eine deaktivierte Person kommt nicht bis zum Code** (WP-35).
        // Bis hier meldete die Anmeldung sie an, und EnsureUserIsActive warf
        // sie mit der naechsten Anfrage hinaus. Mit dem zweiten Faktor ginge
        // ihr dazwischen eine Mail zu.
        if ($benutzer->isDeactivated()) {
            throw ValidationException::withMessages([
                'email' => EnsureUserIsActive::MELDUNG,
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $benutzer;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip());
    }
}
