<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Audit\ImpersonationContext;
use App\Enums\ZweiFaktorVerfahren;
use App\Http\Controllers\Auth\ZweiFaktorAnmeldungController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\ZweiFaktor\Authenticator;
use App\ZweiFaktor\EmailCode;
use App\ZweiFaktor\Versand;
use App\ZweiFaktor\Wiederherstellungscodes;
use App\ZweiFaktor\Zweck;
use App\ZweiFaktor\ZweiterFaktor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Einstellungen → Zweiter Faktor (WP-35, C16). Fuer Praxis und Betreiber.
 *
 * **Das eigene Passwort vor jeder wirksamen Handlung**, im Formular, nicht
 * ueber `password.confirm` -- das endet nach einem POST mit 405 (WP-34a).
 *
 * **Ein Geheimnis zaehlt erst, wenn es bestaetigt ist.** Bis dahin liegt es
 * in der Sitzung. Beim Wechsel bleibt das alte Verfahren aktiv, bis das neue
 * bestaetigt ist -- wer mittendrin abbricht, sperrt sich nicht aus.
 *
 * **Nichts davon waehrend einer Impersonation.** Wer eine Praxis betreut,
 * aendert dabei nicht seinen eigenen Zugang.
 */
final class ZweiFaktorController extends Controller
{
    public const EINRICHTUNG = 'zwei_faktor_einrichtung';

    public const NEUE_CODES = 'zwei_faktor_codes';

    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly Wiederherstellungscodes $codes,
        private readonly EmailCode $emailCode,
        private readonly ZweiterFaktor $zweiterFaktor,
    ) {}

    public function edit(Request $request): Response
    {
        $person = $this->person($request);
        $verfahren = $person->zweiFaktorVerfahren();
        $uebrig = $verfahren === ZweiFaktorVerfahren::Authenticator ? $this->codes->uebrig($person) : null;

        return Inertia::render('settings/ZweiFaktor', [
            'verfahren' => $verfahren?->value,
            'verfahrenLabel' => $verfahren?->label(),
            'adresse' => $person->email,
            'stellen' => (int) config('mrs.zwei_faktor.code_stellen'),
            'codesUebrig' => $uebrig,
            'codesWarnung' => $uebrig !== null && $uebrig <= (int) config('mrs.zwei_faktor.wiederherstellung_warnung_ab'),

            // Einmal, direkt nach dem Erzeugen -- danach nie wieder.
            'neueCodes' => $request->session()->get(self::NEUE_CODES),

            'einrichtung' => $this->einrichtung($request, $person),
        ]);
    }

    public function starteApp(Request $request): RedirectResponse
    {
        $this->nichtInImpersonation();
        $request->validate(['current_password' => ['required', 'current_password']]);

        // Auch wenn die App schon laeuft: ein neues Telefon braucht ein neues
        // Geheimnis. Das alte gilt, bis das neue bestaetigt ist.
        $request->session()->put(self::EINRICHTUNG, [
            'verfahren' => ZweiFaktorVerfahren::Authenticator->value,
            'geheimnis' => $this->authenticator->neuesGeheimnis(),
        ]);

        return redirect()->route('zwei-faktor.edit');
    }

    public function bestaetigeApp(Request $request): RedirectResponse
    {
        $this->nichtInImpersonation();
        $daten = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $person = $this->person($request);

        $einrichtung = $request->session()->get(self::EINRICHTUNG);
        $geheimnis = is_array($einrichtung) && ($einrichtung['verfahren'] ?? null) === ZweiFaktorVerfahren::Authenticator->value
            ? ($einrichtung['geheimnis'] ?? null)
            : null;

        if (! is_string($geheimnis)) {
            return redirect()->route('zwei-faktor.edit');
        }

        $schritt = $this->authenticator->passenderSchritt($geheimnis, (string) $daten['code']);

        if ($schritt === null) {
            throw ValidationException::withMessages(['code' => 'Der Code stimmt nicht. Stimmt die Uhrzeit Ihres Telefons?']);
        }

        $codes = $this->zweiterFaktor->schalteAppEin($person, $geheimnis, $schritt);
        $request->session()->forget(self::EINRICHTUNG);

        return redirect()->route('zwei-faktor.edit')
            ->with(self::NEUE_CODES, $codes)
            ->with('erfolg', 'Der zweite Faktor ist eingeschaltet. Bewahren Sie die Wiederherstellungscodes gut auf.');
    }

    public function starteEmail(Request $request): RedirectResponse
    {
        $this->nichtInImpersonation();
        $request->validate(['current_password' => ['required', 'current_password']]);

        return $this->sendeEinrichtungscode($request, $this->person($request));
    }

    public function erneutEmail(Request $request): RedirectResponse
    {
        $this->nichtInImpersonation();

        if (! $this->richtetEmailEin($request)) {
            return redirect()->route('zwei-faktor.edit');
        }

        return $this->sendeEinrichtungscode($request, $this->person($request));
    }

    public function bestaetigeEmail(Request $request): RedirectResponse
    {
        $this->nichtInImpersonation();
        $daten = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $person = $this->person($request);

        if (! $this->richtetEmailEin($request)) {
            return redirect()->route('zwei-faktor.edit');
        }

        if (! $this->emailCode->pruefe($person, Zweck::Einrichtung, (string) $daten['code'])) {
            throw ValidationException::withMessages(['code' => ZweiFaktorAnmeldungController::FALSCH]);
        }

        $this->zweiterFaktor->schalteEmailEin($person);
        $request->session()->forget(self::EINRICHTUNG);

        return redirect()->route('zwei-faktor.edit')->with('erfolg', 'Der zweite Faktor per E-Mail ist eingeschaltet.');
    }

    public function abbrechen(Request $request): RedirectResponse
    {
        $request->session()->forget(self::EINRICHTUNG);
        $this->emailCode->verwerfe($this->person($request), Zweck::Einrichtung);

        return redirect()->route('zwei-faktor.edit');
    }

    public function codesNeu(Request $request): RedirectResponse
    {
        $this->nichtInImpersonation();
        $request->validate(['current_password' => ['required', 'current_password']]);
        $person = $this->person($request);

        if ($person->zweiFaktorVerfahren() !== ZweiFaktorVerfahren::Authenticator) {
            return redirect()->route('zwei-faktor.edit');
        }

        return redirect()->route('zwei-faktor.edit')
            ->with(self::NEUE_CODES, $this->zweiterFaktor->erneuereCodes($person))
            ->with('erfolg', 'Neue Wiederherstellungscodes erzeugt. Die alten gelten nicht mehr.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->nichtInImpersonation();
        $request->validate(['current_password' => ['required', 'current_password']]);
        $person = $this->person($request);

        if ($person->hatZweiFaktor()) {
            $this->zweiterFaktor->schalteAus($person);
        }

        $request->session()->forget(self::EINRICHTUNG);

        return redirect()->route('zwei-faktor.edit')->with('erfolg', 'Der zweite Faktor ist abgeschaltet.');
    }

    /** "Spaeter" am Hinweis -- er kommt nach `hinweis_pause_tage` wieder. */
    public function hinweisAusblenden(Request $request): RedirectResponse
    {
        $this->nichtInImpersonation();
        $person = $this->person($request);

        $person->zwei_faktor_hinweis_ausgeblendet_at = CarbonImmutable::now();
        $person->save();

        return back();
    }

    private function sendeEinrichtungscode(Request $request, User $person): RedirectResponse
    {
        $zurueck = redirect()->route('zwei-faktor.edit');
        $versand = $this->emailCode->sende($person, Zweck::Einrichtung);

        if ($versand === Versand::Gescheitert) {
            return $zurueck->with('fehler', 'Die Mail mit dem Code ließ sich gerade nicht verschicken. Bitte versuchen Sie es gleich noch einmal.');
        }

        $request->session()->put(self::EINRICHTUNG, ['verfahren' => ZweiFaktorVerfahren::Email->value]);

        return $versand === Versand::Gedrosselt
            ? $zurueck->withErrors(['code' => ZweiFaktorAnmeldungController::wartezeit($this->emailCode->erneutMoeglichIn($person))])
            : $zurueck->with('erfolg', "Ein Code ist an {$person->email} unterwegs.");
    }

    private function richtetEmailEin(Request $request): bool
    {
        $einrichtung = $request->session()->get(self::EINRICHTUNG);

        return is_array($einrichtung) && ($einrichtung['verfahren'] ?? null) === ZweiFaktorVerfahren::Email->value;
    }

    /**
     * Was die Seite waehrend einer Einrichtung zeigt. Der Schluessel der App
     * geht **nur hier** an den Browser, einmal, bevor er gilt.
     *
     * @return array<string, mixed>|null
     */
    private function einrichtung(Request $request, User $person): ?array
    {
        $einrichtung = $request->session()->get(self::EINRICHTUNG);

        if (! is_array($einrichtung)) {
            return null;
        }

        if (($einrichtung['verfahren'] ?? null) === ZweiFaktorVerfahren::Authenticator->value && is_string($einrichtung['geheimnis'] ?? null)) {
            return [
                'verfahren' => ZweiFaktorVerfahren::Authenticator->value,
                'qrCode' => $this->authenticator->qrCode($person, $einrichtung['geheimnis']),
                'schluessel' => $this->authenticator->lesbar($einrichtung['geheimnis']),
            ];
        }

        if (($einrichtung['verfahren'] ?? null) === ZweiFaktorVerfahren::Email->value) {
            return [
                'verfahren' => ZweiFaktorVerfahren::Email->value,
                'erneutIn' => $this->emailCode->erneutMoeglichIn($person),
            ];
        }

        return null;
    }

    private function nichtInImpersonation(): void
    {
        abort_if(app(ImpersonationContext::class)->isActive(), 403);
    }

    private function person(Request $request): User
    {
        $person = $request->user();
        assert($person instanceof User);

        return $person;
    }
}
