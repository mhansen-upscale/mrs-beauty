<?php

declare(strict_types=1);

namespace App\Http\Controllers\Katalog;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Katalog\TreatmentRequest;
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
            'treatments' => Treatment::query()
                ->withCount('appointmentTypes')
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
                    'appointment_types' => $behandlung->appointment_types_count,
                ])
                ->values(),
        ]);
    }

    public function store(TreatmentRequest $request): RedirectResponse
    {
        Treatment::create($request->safe()->except('treatment'));

        return back();
    }

    public function update(TreatmentRequest $request, Treatment $treatment): RedirectResponse
    {
        $treatment->update($request->validated());

        return back();
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
