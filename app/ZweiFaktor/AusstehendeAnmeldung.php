<?php

declare(strict_types=1);

namespace App\ZweiFaktor;

use App\Audit\AuditLogger;
use App\Enums\Anmeldeeingang;
use App\Enums\AuditEvent;
use App\Enums\ZweiFaktorVerfahren;
use App\Http\Middleware\BetreiberLeerlauf;
use App\Models\User;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Der Schritt zwischen Passwort und Anmeldung (WP-35, C16).
 *
 * **Nach dem Passwort ist noch niemand angemeldet.** Die Sitzung traegt nur
 * eine ausstehende Anmeldung: wer, an welchem Eingang, bis wann, mit wie
 * vielen Fehlversuchen -- und einen Fingerabdruck von Passwort und Faktor.
 * Aendert sich eines davon, bevor der Code kommt, gilt sie nicht mehr.
 *
 * **Angemeldet wird an genau einer Stelle, `schliesseAb()`**, fuer beide
 * Eingaenge und mit oder ohne zweiten Faktor. Was der Betreiber-Eingang
 * zusaetzlich tut -- Protokoll und Leerlauffrist (WP-34a) --, steht deshalb
 * hier und nirgends sonst: sonst stuende jede Anmeldung zweimal im Protokoll,
 * die erste, bevor der Code stimmte.
 */
final class AusstehendeAnmeldung
{
    public const SESSION_KEY = 'zwei_faktor_anmeldung';

    public function __construct(
        private readonly EmailCode $emailCode,
        private readonly AuditLogger $protokoll,
        private readonly ZweiterFaktor $zweiterFaktor,
    ) {}

    /**
     * Nach einem richtigen Passwort. Ohne zweiten Faktor ist die Person
     * damit angemeldet, mit zweitem geht es zum Code.
     *
     * Wer hier ankommt, hat die Abweisungen seines Eingangs schon hinter
     * sich: falsches Konto, gesperrte Praxis, deaktiviert.
     */
    public function beginne(Request $request, User $person, Anmeldeeingang $eingang, bool $merken): RedirectResponse
    {
        $merken = $merken && $eingang->erlaubtMerken();

        if (! $person->hatZweiFaktor()) {
            return $this->schliesseAb($request, $person, $eingang, $merken);
        }

        $request->session()->put(self::SESSION_KEY, [
            'benutzer' => $person->uuid,
            'eingang' => $eingang->value,
            'merken' => $merken,
            'bis' => CarbonImmutable::now()->addMinutes((int) config('mrs.zwei_faktor.anmeldung_gueltig_minuten'))->getTimestamp(),
            'fehlversuche' => 0,
            'fingerabdruck' => $this->fingerabdruck($person),
        ]);

        $antwort = redirect()->route($eingang->code());

        if ($person->zweiFaktorVerfahren() === ZweiFaktorVerfahren::Email) {
            $antwort = $this->mitVersand($antwort, $this->emailCode->sende($person, Zweck::Anmeldung));
        }

        return $antwort;
    }

    /** Die ausstehende Anmeldung dieser Sitzung, gueltig oder nicht -- oder keine. */
    public function lies(Request $request): ?OffeneAnmeldung
    {
        $zustand = $request->session()->get(self::SESSION_KEY);

        if (! is_array($zustand) || ! is_string($zustand['eingang'] ?? null)) {
            return null;
        }

        $eingang = Anmeldeeingang::tryFrom($zustand['eingang']);

        if (! $eingang instanceof Anmeldeeingang) {
            return null;
        }

        $person = $this->person($zustand['benutzer'] ?? null);

        $gueltig = $person instanceof User
            && (int) ($zustand['bis'] ?? 0) >= CarbonImmutable::now()->getTimestamp()
            && is_string($zustand['fingerabdruck'] ?? null)
            && hash_equals($this->fingerabdruck($person), $zustand['fingerabdruck'])
            && $person->hatZweiFaktor()
            && ! $person->isDeactivated()
            && $person->organization?->suspended_at === null;

        return new OffeneAnmeldung(
            eingang: $eingang,
            merken: (bool) ($zustand['merken'] ?? false),
            fehlversuche: (int) ($zustand['fehlversuche'] ?? 0),
            person: $person,
            gueltig: $gueltig,
        );
    }

    /**
     * Meldet an. Die einzige Stelle, an der das bei der Anmeldung geschieht.
     */
    public function schliesseAb(Request $request, User $person, Anmeldeeingang $eingang, bool $merken): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        Auth::guard('web')->login($person, $merken && $eingang->erlaubtMerken());

        $request->session()->regenerate();

        if ($eingang === Anmeldeeingang::Betreiber) {
            $this->protokoll->record(
                ereignis: AuditEvent::OperatorLoggedIn,
                gegenstand: $person,
                ohneOrganisation: true,
            );

            // Die Leerlauffrist beginnt mit der Anmeldung, nicht mit der
            // naechsten Anfrage -- und die Anmeldung ist der Code.
            $request->session()->put(BetreiberLeerlauf::SESSION_KEY, CarbonImmutable::now()->getTimestamp());
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Ein falscher Code. Gibt zurueck, ob die Anmeldung damit verworfen ist.
     */
    public function fehlversuch(Request $request, OffeneAnmeldung $offen): bool
    {
        $person = $offen->person();

        RateLimiter::hit($this->drosselschluessel($person), 3600);

        if ($offen->eingang === Anmeldeeingang::Betreiber) {
            // Wie jeder Fehlversuch am Eingang des Betreibers (WP-34a).
            $this->protokoll->record(
                ereignis: AuditEvent::OperatorLoginFailed,
                gegenstand: $person,
                kontext: ['schritt' => 'code'],
                ohneOrganisation: true,
            );
        }

        $fehlversuche = $offen->fehlversuche + 1;

        if ($fehlversuche >= (int) config('mrs.zwei_faktor.max_versuche')) {
            $this->verwerfe($request);
            $this->zweiterFaktor->anmeldungVerworfen($person);

            return true;
        }

        $request->session()->put(self::SESSION_KEY.'.fehlversuche', $fehlversuche);

        return false;
    }

    /**
     * Zu viele falsche Codes fuer diese Person in der letzten Stunde, ueber
     * alle Sitzungen? Wer sich neu anmeldet, um fuenf weitere Versuche zu
     * bekommen, bekommt sie nicht.
     */
    public function gedrosselt(User $person): bool
    {
        return RateLimiter::tooManyAttempts($this->drosselschluessel($person), (int) config('mrs.zwei_faktor.fehlversuche_je_stunde'));
    }

    public function verwerfe(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /** Die Rueckmeldung zu einem Code per E-Mail, an eine Weiterleitung gehaengt. */
    public function mitVersand(RedirectResponse $antwort, Versand $versand): RedirectResponse
    {
        return match ($versand) {
            Versand::Gesendet => $antwort,
            Versand::Gedrosselt => $antwort->with('hinweise', ['Ein Code ist schon unterwegs. Einen neuen können Sie gleich anfordern.']),
            Versand::Gescheitert => $antwort->with('fehler', 'Die Mail mit dem Code ließ sich gerade nicht verschicken. Bitte fordern Sie gleich einen neuen an.'),
        };
    }

    /**
     * Passwort und Faktor, wie sie beim Passwortschritt waren. Ein neues
     * Passwort, ein zurueckgesetzter oder gewechselter Faktor -- und die
     * ausstehende Anmeldung gilt nicht mehr.
     */
    private function fingerabdruck(User $person): string
    {
        return hash('sha256', implode('|', [
            $person->getAuthPassword(),
            $person->zweiFaktorVerfahren()?->value,
            $person->zwei_faktor_bestaetigt_at?->getTimestamp(),
        ]));
    }

    private function person(mixed $uuid): ?User
    {
        if (! is_string($uuid)) {
            return null;
        }

        try {
            return User::query()->whereKey(Uuid::toBinary($uuid))->first();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function drosselschluessel(User $person): string
    {
        return "zwei-faktor:fehlversuche:{$person->uuid}";
    }
}
