<?php

declare(strict_types=1);

namespace App\Http\Controllers\Katalog;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Katalog\TreatmentRequest;
use App\Models\Practitioner;
use App\Models\Treatment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TreatmentController extends Controller
{
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
                ->with('practitioners')
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
                ])
                ->values(),
        ]);
    }

    public function store(TreatmentRequest $request): RedirectResponse
    {
        $behandlung = Treatment::create($request->safe()->except(['treatment', 'practitioners']));

        $this->setzeBehandler($behandlung, $request);

        return back();
    }

    public function update(TreatmentRequest $request, Treatment $treatment): RedirectResponse
    {
        $treatment->update($request->safe()->except('practitioners'));

        $this->setzeBehandler($treatment, $request);

        return back();
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
