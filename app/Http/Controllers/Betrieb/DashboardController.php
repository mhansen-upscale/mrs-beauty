<?php

declare(strict_types=1);

namespace App\Http\Controllers\Betrieb;

use App\Backoffice\Installationskennzahlen;
use App\Betrieb\Betriebslage;
use App\Betrieb\Praxiskennzahlen;
use App\Enums\Ability;
use App\Enums\OperatorAbility;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\QrCode;
use App\Tenancy\TenantContext;
use App\Warteliste\Klaerung;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Dashboard.
 *
 * In dieser Reihenfolge: **was nicht laeuft** (Regel 4), **was ein Mensch
 * entscheiden muss** (die wackeligen Termine der Warteliste), **die Zahlen
 * des Tages** und **der oeffentliche Buchungslink**, der bis WP-19 nirgends
 * im Produkt zu finden war. Die Kette von der Anzeige bis zum Umsatz steht
 * unter Auswertung (WP-32b).
 *
 * **Der Betreiber sieht hier die Installation**, nicht eine Praxis: er
 * gehoert zu keiner (WP-34). Abos, Nutzung des Monats und Betrieb -- Summen
 * ueber alle Praxen, in einem begruendeten Querzugriff. Das Backoffice
 * bleibt die Liste der Praxen.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly Betriebslage $lage,
        private readonly Klaerung $klaerung,
        private readonly Praxiskennzahlen $kennzahlen,
        private readonly Installationskennzahlen $installation,
    ) {}

    public function __invoke(Request $request, TenantContext $mandant): Response
    {
        $organisation = $mandant->current();
        $benutzer = $request->user();

        if (! $organisation instanceof Organization) {
            if ($benutzer instanceof User && $benutzer->istBetreiber()) {
                return Inertia::render('DashboardBetreiber', [
                    'kennzahlen' => $this->installationFuer($benutzer),
                    'warnungTage' => (int) config('mrs.backoffice.testphase_warnung_tage'),
                ]);
            }

            return Inertia::render('Dashboard', ['booking' => null, 'betrieb' => null, 'aufgaben' => null, 'kennzahlen' => null]);
        }

        $adresse = route('buchung.zeigen', ['praxis' => $organisation->slug]);

        return Inertia::render('Dashboard', [
            'booking' => [
                'url' => $adresse,
                'slug' => $organisation->slug,
                // Serverseitig erzeugt -- die Seiten dieses Produkts laden
                // keine Skripte von fremden Adressen -- und als **Datenadresse**
                // ausgeliefert, nicht als eingesetztes SVG.
                //
                // Der Unterschied ist Regel 5: ein SVG, das die Oberflaeche
                // mit v-html einsetzt, waere die einzige Stelle im Produkt,
                // an der Auszeichnung aus einer Zeichenkette entsteht. In
                // einem <img> kann dieselbe Datei nichts ausfuehren.
                'qr' => 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg($adresse)),
            ],

            // **Regel 4**: ein Ausfall erzeugt einen Hinweis im Produkt,
            // nicht nur im Log. Das Dashboard ist die Stelle, an der jemand
            // ihn sieht, ohne ihn zu suchen.
            'betrieb' => $this->lage->fuerMandant(),

            // Was ein Mensch entscheiden muss, bevor es weitergeht -- nur fuer
            // die, die es entscheiden duerfen.
            'aufgaben' => Gate::allows(Ability::ManageWaitlist->value)
                ? ['klaerungen' => $this->klaerung->offene()->count()]
                : null,

            // Zahlen, keine Inhalte -- und jede nur fuer die, die die Sache
            // dahinter sehen duerfen.
            'kennzahlen' => $benutzer instanceof User
                ? $this->kennzahlen->fuer($benutzer)
                : null,
        ]);
    }

    /**
     * Die Kennzahlen der Installation -- **Geld nur fuer die, die es sehen
     * duerfen** (WP-34a). Customer Success sieht, wie es um die Abos steht,
     * aber keinen Umsatz und keine Kosten. Die Werte fehlen in der Antwort,
     * nicht nur in der Anzeige.
     *
     * @return array<string, mixed>
     */
    private function installationFuer(User $betreiber): array
    {
        $kennzahlen = $this->installation->jetzt();

        if (! $betreiber->betreiberDarf(OperatorAbility::FinanzenSehen)) {
            data_set($kennzahlen, 'abos.mrrCent', null);
            data_set($kennzahlen, 'monat.modellkostenUsdCent', null);
        }

        return $kennzahlen;
    }
}
