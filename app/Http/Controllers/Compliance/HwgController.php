<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance;

use App\Compliance\Befund;
use App\Compliance\Pruefgegenstand;
use App\Compliance\Pruefung;
use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\ComplianceRuleset;
use App\Models\Treatment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die HWG-Pruefung -- das Differenzierungsmerkmal.
 *
 * **Das Produkt ist eine Pruefhilfe, keine Rechtsberatung.** Diese Seite
 * sagt, was aufgefallen ist, nennt die Fundstelle und schlaegt eine
 * Formulierung vor. Sie spricht kein Urteil, und sie sagt das auch.
 */
final class HwgController extends Controller
{
    public function __construct(private readonly Pruefung $pruefung) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Ability::ManageBrandGuide->value);

        $fassung = ComplianceRuleset::geltend();

        return Inertia::render('hwg/Index', [
            'regelwerk' => $fassung === null ? null : [
                'version' => $fassung->version,
                'rechtsstand' => $fassung->legal_as_of->toDateString(),
                'changelog' => $fassung->changelog,
                'geprueft' => $fassung->juristischGeprueft(),
                'geprueftVon' => $fassung->reviewed_by,
                'geprueftAm' => $fassung->reviewed_at?->toDateString(),
            ],

            // Der zuletzt gepruefte Text samt Ergebnis -- er steht in der
            // Sitzung, nicht in der Datenbank: ein Entwurf ist kein Vorgang.
            'probe' => $request->session()->get('hwg.probe'),

            // Der Bestand. **Nicht die Buchungsseite**: die zeigt weder
            // Preis noch Behandlungsbeschreibung (geprueft in
            // tests/Feature/Buchung). Die Beschreibungen speisen das
            // Praxiswissen des Assistenten (WP-22) und ab WP-31 die
            // Anzeigenvorschlaege -- dort werden sie zu Aussagen.
            'katalog' => $this->katalog(),

            'formate' => (array) config('mrs.hwg.alternativformate'),
            'bussgeld' => (int) config('mrs.hwg.bussgeld_hinweis'),
        ]);
    }

    /**
     * Einen Text pruefen, ohne ihn zu speichern.
     *
     * Der nuetzlichste Weg, solange es noch keine Anzeigenvorschlaege gibt:
     * die Praxis fuegt ihren Text ein und sieht die Ampel, bevor irgendetwas
     * hinausgeht.
     */
    public function pruefen(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageBrandGuide->value);

        $daten = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'hatBild' => ['boolean'],
        ]);

        $ergebnis = $this->pruefung->pruefe(new Pruefgegenstand(
            text: (string) $daten['text'],
            hatBild: (bool) ($daten['hatBild'] ?? false),
        ));

        return back()->with('hwg.probe', [
            'text' => (string) $daten['text'],
            'hatBild' => (bool) ($daten['hatBild'] ?? false),
            ...$ergebnis->toArray(),
        ]);
    }

    /**
     * Die Behandlungsbeschreibungen.
     *
     * **Nur die mit Text.** Ein Katalogeintrag ohne Beschreibung traegt keine
     * Aussage -- ihn zu beanstanden, weil sein Name eine Behandlung nennt,
     * waere Laerm. Und Laerm schaltet eine Pruefung ab.
     *
     * @return list<array<string, mixed>>
     */
    private function katalog(): array
    {
        /** @var list<array<string, mixed>> */
        return Treatment::query()
            ->aktiv()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderBy('name')
            ->get()
            ->map(function (Treatment $behandlung): array {
                $ergebnis = $this->pruefung->pruefe(new Pruefgegenstand(
                    text: (string) $behandlung->description,
                ));

                return [
                    'uuid' => $behandlung->uuid,
                    'name' => $behandlung->name,
                    'ampel' => $ergebnis->ampel->value,
                    'ampelText' => $ergebnis->ampel->label(),
                    'befunde' => array_map(fn (Befund $b): array => $b->toArray(), $ergebnis->befunde),
                ];
            })
            ->values()
            ->all();
    }
}
