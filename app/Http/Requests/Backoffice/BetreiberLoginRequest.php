<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Die Anmeldung der Betreiber (WP-34a, Entscheidung C14).
 *
 * **Strenger als die der Praxen**, weil ein Betreiberkonto quer ueber alle
 * Praxen reicht und es vorerst keinen zweiten Faktor gibt: weniger
 * Versuche, kein Angemeldet-Bleiben, jede Anmeldung und jeder Fehlversuch
 * im Protokoll.
 *
 * **Ein Fehlversuch nennt das Konto, wenn es eines gibt -- nie die
 * eingetippte Adresse** (C5). Ein Protokoll voller vertippter
 * E-Mail-Adressen ist eine Adressliste.
 */
final class BetreiberLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
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
     * @throws ValidationException
     */
    public function authenticate(AuditLogger $protokoll): User
    {
        $this->pruefeDrosselung();

        $benutzer = Auth::validate($this->only('email', 'password'))
            ? Auth::getLastAttempted()
            : null;

        // **Dieselbe Meldung fuer alles, was nicht hinein darf**: falsches
        // Passwort, unbekannte Adresse, Praxiskonto. Erst nach der Pruefung
        // abgewiesen -- vorher verriete die Antwort, welche Adressen
        // Betreiberkonten sind.
        if (! $benutzer instanceof User || ! $benutzer->istBetreiber()) {
            RateLimiter::hit($this->drosselschluessel(), (int) config('mrs.backoffice.login_sperrminuten') * 60);

            $protokoll->record(
                ereignis: AuditEvent::OperatorLoginFailed,
                gegenstand: $this->betreiberkonto(),
                ohneOrganisation: true,
            );

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        // **Nie dauerhaft angemeldet**, gleich was das Formular schickt.
        Auth::login($benutzer, false);

        RateLimiter::clear($this->drosselschluessel());

        $protokoll->record(
            ereignis: AuditEvent::OperatorLoggedIn,
            gegenstand: $benutzer,
            ohneOrganisation: true,
        );

        return $benutzer;
    }

    /**
     * Das Betreiberkonto zur eingetippten Adresse, falls es eines gibt.
     *
     * Ein Praxiskonto zaehlt nicht: sein Fehlversuch hier gehoert nicht in
     * das Protokoll des Betreibers, und im Protokoll einer Praxis haette er
     * nichts zu suchen, weil die Praxis ihn nie sehen koennte.
     */
    private function betreiberkonto(): ?User
    {
        return User::query()
            ->where('email', $this->string('email')->toString())
            ->whereNotNull('operator_role')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    private function pruefeDrosselung(): void
    {
        if (! RateLimiter::tooManyAttempts($this->drosselschluessel(), (int) config('mrs.backoffice.login_versuche'))) {
            return;
        }

        event(new Lockout($this));

        $sekunden = RateLimiter::availableIn($this->drosselschluessel());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $sekunden,
                'minutes' => ceil($sekunden / 60),
            ]),
        ]);
    }

    /** Eigener Schluessel: Fehlversuche an /login zaehlen hier nicht mit und umgekehrt. */
    private function drosselschluessel(): string
    {
        return 'betreiber|'.Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip());
    }
}
