<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Anmeldeeingang;
use App\Enums\ZweiFaktorVerfahren;
use App\Http\Controllers\Controller;
use App\ZweiFaktor\AusstehendeAnmeldung;
use App\ZweiFaktor\Authenticator;
use App\ZweiFaktor\EmailCode;
use App\ZweiFaktor\OffeneAnmeldung;
use App\ZweiFaktor\Versand;
use App\ZweiFaktor\Wiederherstellungscodes;
use App\ZweiFaktor\Zweck;
use App\ZweiFaktor\ZweiterFaktor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der Code-Schritt der Anmeldung (WP-35), fuer beide Eingaenge.
 *
 * Welcher Eingang, steht an der Route (`eingang`), nicht im Formular: Eine
 * ausstehende Anmeldung der Praxis gilt an der Code-Route des Betreibers
 * nicht und umgekehrt.
 *
 * Gast-Routen -- nach dem Passwort ist noch niemand angemeldet.
 */
final class ZweiFaktorAnmeldungController extends Controller
{
    public const FALSCH = 'Der Code stimmt nicht.';

    public const ABGELAUFEN = 'Die Anmeldung ist abgelaufen. Bitte melden Sie sich erneut an.';

    public const VERWORFEN = 'Zu viele falsche Codes. Bitte melden Sie sich erneut an.';

    public const GEDROSSELT = 'Zu viele falsche Codes in der letzten Stunde. Bitte versuchen Sie es später noch einmal.';

    public function __construct(
        private readonly AusstehendeAnmeldung $anmeldung,
        private readonly Authenticator $authenticator,
        private readonly Wiederherstellungscodes $codes,
        private readonly EmailCode $emailCode,
        private readonly ZweiterFaktor $zweiterFaktor,
    ) {}

    public function show(Request $request, string $eingang): Response|RedirectResponse
    {
        $eingang = Anmeldeeingang::from($eingang);
        $offen = $this->offen($request, $eingang);

        if ($offen instanceof RedirectResponse) {
            return $offen;
        }

        $person = $offen->person();
        $email = $person->zweiFaktorVerfahren() === ZweiFaktorVerfahren::Email;

        return Inertia::render('auth/ZweiFaktor', [
            'eingang' => $eingang->value,
            'verfahren' => $person->zweiFaktorVerfahren()?->value,
            'stellen' => (int) config('mrs.zwei_faktor.code_stellen'),
            'erneutIn' => $email ? $this->emailCode->erneutMoeglichIn($person) : null,
            'aktionen' => [
                'pruefen' => route($eingang->code('pruefen')),
                'erneut' => $email ? route($eingang->code('erneut')) : null,
                'abbrechen' => route($eingang->code('abbrechen')),
            ],
        ]);
    }

    public function pruefe(Request $request, string $eingang): RedirectResponse
    {
        $eingang = Anmeldeeingang::from($eingang);
        $offen = $this->offen($request, $eingang);

        if ($offen instanceof RedirectResponse) {
            return $offen;
        }

        $daten = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $code = (string) $daten['code'];
        $person = $offen->person();

        // Vor der Pruefung: wer gedrosselt ist, bekommt auch mit dem richtigen
        // Code keine Antwort, die ihn bestaetigt.
        if ($this->anmeldung->gedrosselt($person)) {
            $this->anmeldung->verwerfe($request);

            return redirect()->route($eingang->anmeldung())->withErrors(['email' => self::GEDROSSELT]);
        }

        $mitWiederherstellung = false;

        if ($person->zweiFaktorVerfahren() === ZweiFaktorVerfahren::Email) {
            $richtig = $this->emailCode->pruefe($person, Zweck::Anmeldung, $code);
        } elseif (Wiederherstellungscodes::siehtAusWie($code)) {
            $richtig = $mitWiederherstellung = $this->codes->loese($person, $code);
        } else {
            $richtig = $this->authenticator->pruefe($person, $code);
        }

        if (! $richtig) {
            if ($this->anmeldung->fehlversuch($request, $offen)) {
                return redirect()->route($eingang->anmeldung())->withErrors(['email' => self::VERWORFEN]);
            }

            return redirect()->route($eingang->code())->withErrors(['code' => self::FALSCH]);
        }

        $antwort = $this->anmeldung->schliesseAb($request, $person, $eingang, $offen->merken);

        if ($mitWiederherstellung) {
            // Nach der Anmeldung protokolliert: dann ist die Person die
            // Handelnde, nicht "System".
            $this->zweiterFaktor->wiederherstellungscodeEingeloest($person);

            $uebrig = $this->codes->uebrig($person);

            if ($uebrig <= (int) config('mrs.zwei_faktor.wiederherstellung_warnung_ab')) {
                $antwort->with('hinweise', [self::restlicheCodes($uebrig)]);
            }
        }

        return $antwort;
    }

    public function erneut(Request $request, string $eingang): RedirectResponse
    {
        $eingang = Anmeldeeingang::from($eingang);
        $offen = $this->offen($request, $eingang);

        if ($offen instanceof RedirectResponse) {
            return $offen;
        }

        $person = $offen->person();
        $zurueck = redirect()->route($eingang->code());

        if ($person->zweiFaktorVerfahren() !== ZweiFaktorVerfahren::Email) {
            return $zurueck;
        }

        $versand = $this->emailCode->sende($person, Zweck::Anmeldung);

        return match ($versand) {
            Versand::Gesendet => $zurueck->with('erfolg', 'Ein neuer Code ist unterwegs.'),
            Versand::Gedrosselt => $zurueck->withErrors(['code' => self::wartezeit($this->emailCode->erneutMoeglichIn($person))]),
            Versand::Gescheitert => $this->anmeldung->mitVersand($zurueck, $versand),
        };
    }

    public function abbrechen(Request $request, string $eingang): RedirectResponse
    {
        $this->anmeldung->verwerfe($request);

        return redirect()->route(Anmeldeeingang::from($eingang)->anmeldung());
    }

    /** Die Rueckmeldung, wenn die Codes zur Neige gehen. */
    public static function restlicheCodes(int $uebrig): string
    {
        return match (true) {
            $uebrig === 0 => 'Sie haben keine Wiederherstellungscodes mehr. Erzeugen Sie neue unter Einstellungen → Zweiter Faktor.',
            $uebrig === 1 => 'Nur noch 1 Wiederherstellungscode übrig. Erzeugen Sie neue unter Einstellungen → Zweiter Faktor.',
            default => "Nur noch {$uebrig} Wiederherstellungscodes übrig. Erzeugen Sie neue unter Einstellungen → Zweiter Faktor.",
        };
    }

    public static function wartezeit(int $sekunden): string
    {
        return $sekunden > 120
            ? 'Für diese Stunde sind genug Codes verschickt. Bitte versuchen Sie es in '.(int) ceil($sekunden / 60).' Minuten erneut.'
            : "Einen neuen Code gibt es in {$sekunden} Sekunden.";
    }

    /**
     * Die ausstehende Anmeldung dieses Eingangs -- oder die Weiterleitung
     * dorthin, wo die Person hingehoert.
     */
    private function offen(Request $request, Anmeldeeingang $eingang): OffeneAnmeldung|RedirectResponse
    {
        $offen = $this->anmeldung->lies($request);

        if (! $offen instanceof OffeneAnmeldung) {
            return redirect()->route($eingang->anmeldung());
        }

        if ($offen->eingang !== $eingang) {
            return redirect()->route($offen->eingang->code());
        }

        if (! $offen->gilt()) {
            $this->anmeldung->verwerfe($request);

            return redirect()->route($eingang->anmeldung())->withErrors(['email' => self::ABGELAUFEN]);
        }

        return $offen;
    }
}
