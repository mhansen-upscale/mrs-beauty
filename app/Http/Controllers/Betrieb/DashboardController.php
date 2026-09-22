<?php

declare(strict_types=1);

namespace App\Http\Controllers\Betrieb;

use App\Betrieb\Betriebslage;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\QrCode;
use App\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Dashboard.
 *
 * Es zeigt vorerst eines: **den oeffentlichen Buchungslink**. Der war bis
 * jetzt nirgends im Produkt zu finden -- eine Praxis, die ihn auf die eigene
 * Website oder in die Instagram-Biografie setzen will, musste ihn raten.
 *
 * Was sonst hier steht, entscheidet sich mit WP-32: die Kette von der Anzeige
 * bis zum Umsatz ist die Zahl, die das Abo rechtfertigt, und sie gehoert an
 * diese Stelle.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly Betriebslage $lage) {}

    public function __invoke(TenantContext $mandant): Response
    {
        $organisation = $mandant->current();

        if (! $organisation instanceof Organization) {
            return Inertia::render('Dashboard', ['booking' => null, 'betrieb' => null]);
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
        ]);
    }
}
