<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Middleware\EnsurePraxisNichtGesperrt;
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
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
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

        Auth::login($benutzer, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
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
