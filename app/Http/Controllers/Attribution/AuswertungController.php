<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attribution;

use App\Attribution\Auswertung;
use App\Attribution\Auswertungszeile;
use App\Enums\Ability;
use App\Enums\AttributionModel;
use App\Enums\Aufschluesselung;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Kette von der Anzeige bis zum Umsatz.
 *
 * **Die bekannten Grenzen stehen auf der Seite**, nicht nur in der
 * Dokumentation (docs/fachlogik/attribution.md): ein Kunde, der eine Luecke
 * selbst entdeckt, verliert das Vertrauen in alle Zahlen.
 */
final class AuswertungController extends Controller
{
    public function __construct(private readonly Auswertung $auswertung) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Ability::ViewInsights->value);

        $tage = $this->tage($request);
        $bis = CarbonImmutable::now()->endOfDay();
        $von = $bis->subDays($tage - 1)->startOfDay();

        $nach = Aufschluesselung::tryFrom((string) $request->query('nach', '')) ?? Aufschluesselung::Kampagne;

        // Ohne Angabe gilt der eingefrorene Stand -- das Modell von damals.
        $modell = AttributionModel::tryFrom((string) $request->query('modell', ''));

        $zeilen = $this->auswertung->zeilen($von, $bis, $nach, $modell);

        return Inertia::render('auswertung/Index', [
            'zeilen' => array_map(fn (Auswertungszeile $z): array => $z->toArray(), $zeilen),
            'summe' => $this->auswertung->summe($zeilen)->toArray(),

            // Die Kostenkennzahlen teilen nur durch das, was einer Kampagne
            // zugeordnet ist -- sonst schmeicheln sie (attribution.md,
            // Abschnitt Kennzahlen).
            'zugeordnet' => $this->auswertung->zugeordnet($zeilen)->toArray(),

            'zeitraum' => [
                'tage' => $tage,
                'von' => $von->toDateString(),
                'bis' => $bis->toDateString(),
                'auswahl' => array_map(intval(...), (array) config('mrs.ads.ranges')),
            ],

            'nach' => $nach->value,
            'traegtKosten' => $nach->traegtKosten(),

            'aufschluesselungen' => collect(Aufschluesselung::cases())
                ->map(fn (Aufschluesselung $a): array => [
                    'value' => $a->value,
                    'label' => $a->label(),
                    'kosten' => $a->traegtKosten(),
                ])
                ->values(),

            'modell' => $modell?->value,

            'modelle' => collect(AttributionModel::cases())
                ->map(fn (AttributionModel $m): array => [
                    'value' => $m->value,
                    'label' => $m->label(),
                    'beschreibung' => $m->beschreibung(),
                ])
                ->values(),

            // **Der Wert gehoert sichtbar ins Dashboard**, nicht allein in
            // eine Konfigurationsdatei: mit einem kuerzeren Fenster wird
            // systematisch zu wenig zugeordnet, und die Praxis haelt ihre
            // Werbung fuer schlechter, als sie ist.
            'rueckblick' => (int) config('mrs.attribution.lookback_days'),
        ]);
    }

    private function tage(Request $request): int
    {
        $erlaubt = array_map(intval(...), (array) config('mrs.ads.ranges'));
        $gewaehlt = is_numeric($request->query('zeitraum')) ? (int) $request->query('zeitraum') : null;

        return in_array($gewaehlt, $erlaubt, true) ? $gewaehlt : (int) ($erlaubt[1] ?? 30);
    }
}
