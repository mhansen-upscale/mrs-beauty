<?php

declare(strict_types=1);

namespace App\Http\Controllers\Betrieb;

use App\Betrieb\Betriebslage;
use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\QrCode;
use App\Tenancy\TenantContext;
use App\Warteliste\Klaerung;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Dashboard.
 *
 * Drei Dinge, in dieser Reihenfolge: **was nicht laeuft** (Regel 4), **was
 * ein Mensch entscheiden muss** (die wackeligen Termine der Warteliste) und
 * **der oeffentliche Buchungslink**, der bis WP-19 nirgends im Produkt zu
 * finden war. Die Kette von der Anzeige bis zum Umsatz steht unter
 * Auswertung (WP-32b).
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly Betriebslage $lage,
        private readonly Klaerung $klaerung,
    ) {}

    public function __invoke(TenantContext $mandant): Response
    {
        $organisation = $mandant->current();

        if (! $organisation instanceof Organization) {
            return Inertia::render('Dashboard', ['booking' => null, 'betrieb' => null, 'aufgaben' => null]);
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
        ]);
    }
}
