<?php

declare(strict_types=1);

namespace App\Http\Controllers\Katalog;

use App\Compliance\Veroeffentlichungspruefung;
use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Katalog\TreatmentRequest;
use App\Models\ComplianceCheck;
use App\Models\Practitioner;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TreatmentController extends Controller
{
    public function __construct(private readonly Veroeffentlichungspruefung $hwg) {}

    public function index(): Response
    {
        Gate::authorize(Ability::ManageCatalog->value);

        return Inertia::render('katalog/Behandlungen', [
            // Wer die Behandlung machen kann -- zur Auswahl und zur Anzeige.
            'practitioners' => Practitioner::query()
                ->where('is_active', true)
                ->orderBy('last_name')
                ->get()
                ->map(fn (Practitioner $behandler): array => [
                    'uuid' => $behandler->uuid,
                    'name' => $behandler->name(),
                ])
                ->values(),

            'treatments' => Treatment::query()
                ->withCount('appointmentTypes')
                ->with(['practitioners', 'pruefung'])
                ->orderBy('name')
                ->get()
                ->map(fn (Treatment $behandlung): array => [
                    'uuid' => $behandlung->uuid,
                    'name' => $behandlung->name,
                    'slug' => $behandlung->slug,
                    'description' => $behandlung->description,
                    'category' => $behandlung->category,
                    'price_from_cents' => $behandlung->price_from_cents,
                    'price_to_cents' => $behandlung->price_to_cents,
                    'avg_revenue_cents' => $behandlung->avg_revenue_cents,
                    'is_active' => $behandlung->is_active,
                    'all_practitioners' => $behandlung->all_practitioners,
                    'practitioners' => $behandlung->practitioners
                        ->map(fn (Practitioner $behandler): string => (string) $behandler->uuid)
                        ->values(),
                    'practitioner_names' => $behandlung->behandler()
                        ->map(fn (Practitioner $behandler): string => $behandler->name())
                        ->values(),
                    'appointment_types' => $behandlung->appointment_types_count,

                    // Was die Buchungsseite zeigen darf (WP-30). Die Praxis
                    // soll hier sehen, warum ihre Beschreibung dort fehlt --
                    // nicht erst auf der Buchungsseite.
                    'hwg' => $this->hwgStand($behandlung),
                ])
                ->values(),
        ]);
    }

    public function store(TreatmentRequest $request): RedirectResponse
    {
        $behandlung = Treatment::create($request->safe()->except(['treatment', 'practitioners']));

        $this->setzeBehandler($behandlung, $request);

        // Beschreibung und Preis sind Werbung auf der Buchungsseite: geprueft
        // wird beim Speichern, nicht beim Anzeigen (WP-30).
        $this->hwg->behandlung($behandlung);

        return back();
    }

    public function update(TreatmentRequest $request, Treatment $treatment): RedirectResponse
    {
        $treatment->update($request->safe()->except('practitioners'));

        $this->setzeBehandler($treatment, $request);

        if (! $this->hwg->freigegeben($treatment) || $treatment->wasChanged(['description', 'price_from_cents', 'price_to_cents'])) {
            $this->hwg->behandlung($treatment);
        }

        return back();
    }

    /**
     * Uebersteuern mit Pflichtbegruendung (Entscheidung C3) -- wie bei einem
     * Anzeigenentwurf. Protokolliert wird es am Pruefergebnis.
     */
    public function uebersteuern(Request $request, Treatment $treatment): RedirectResponse
    {
        Gate::authorize(Ability::ManageCatalog->value);

        $daten = $request->validate([
            'grund' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'grund.required' => 'Bitte begründen Sie, warum dieser Befund hier nicht zutrifft.',
            'grund.min' => 'Bitte begründen Sie es in einem ganzen Satz.',
        ]);

        $pruefung = $treatment->pruefung()->first();
        $benutzer = $request->user();

        if (! $pruefung instanceof ComplianceCheck || ! $benutzer instanceof User) {
            abort(404);
        }

        $pruefung->override_reason = (string) $daten['grund'];
        $pruefung->overridden_by_user_id = $benutzer->getKey();
        $pruefung->overridden_at = CarbonImmutable::now();
        $pruefung->save();

        return back()->with('erfolg', 'Die Übersteuerung ist festgehalten.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function hwgStand(Treatment $behandlung): ?array
    {
        if ($behandlung->oeffentlicherText() === '') {
            return null;
        }

        $pruefung = $behandlung->pruefung;

        return [
            'ampel' => $pruefung?->result->value,
            'befunde' => $pruefung?->befunde() ?? [],
            'uebersteuert' => $pruefung?->uebersteuert() ?? false,
            'sichtbar' => $this->hwg->freigegeben($behandlung),
        ];
    }

    /**
     * Die Behandlerfreigabe.
     *
     * Bei "alle" wird die Zuordnung geleert und nicht etwa mit allen
     * gefuellt: sonst waere ein spaeter eingestellter Behandler still
     * ausgeschlossen, und niemand haette es gemerkt.
     */
    private function setzeBehandler(Treatment $behandlung, TreatmentRequest $request): void
    {
        if ($behandlung->all_practitioners) {
            $behandlung->practitioners()->sync([]);

            return;
        }

        /** @var array<int, string> $gewaehlt */
        $gewaehlt = $request->validated('practitioners', []);

        $behandlung->practitioners()->sync(
            Practitioner::query()->whereUuid($gewaehlt)->pluck('id')->all()
        );
    }

    /**
     * Deaktivieren statt loeschen. Eine geloeschte Behandlung nimmt ihre
     * Terminarten mit -- und fehlt anschliessend in jeder Auswertung, die
     * einen historischen Umsatz zuordnen will.
     */
    public function deactivate(Treatment $treatment): RedirectResponse
    {
        Gate::authorize(Ability::ManageCatalog->value);

        $treatment->is_active = false;
        $treatment->save();

        return back();
    }

    public function activate(Treatment $treatment): RedirectResponse
    {
        Gate::authorize(Ability::ManageCatalog->value);

        $treatment->is_active = true;
        $treatment->save();

        return back();
    }
}
