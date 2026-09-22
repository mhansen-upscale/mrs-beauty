<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stammdaten;

use App\Enums\Ability;
use App\Enums\ClosureReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stammdaten\ClosureRequest;
use App\Http\Requests\Stammdaten\LocationRequest;
use App\Models\Location;
use App\Models\LocationClosure;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class LocationController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Ability::ManageMasterData->value);

        return Inertia::render('stammdaten/Standorte', [
            'locations' => Location::query()
                ->with(['closures' => fn ($abfrage) => $abfrage->orderBy('starts_at')])
                ->orderBy('name')
                ->get()
                ->map(fn (Location $standort): array => [
                    'uuid' => $standort->uuid,
                    'name' => $standort->name,
                    'slug' => $standort->slug,
                    'timezone' => $standort->timezone,
                    'street' => $standort->street,
                    'postal_code' => $standort->postal_code,
                    'city' => $standort->city,
                    'country' => $standort->country,
                    'phone' => $standort->phone,
                    'email' => $standort->email,
                    'is_active' => $standort->is_active,
                    'practitioners' => $standort->practitioners()->count(),
                    'closures' => $standort->closures->map(fn (LocationClosure $zeit): array => [
                        'uuid' => $zeit->uuid,
                        'reason' => $zeit->reason->value,
                        'reason_label' => $zeit->reason->label(),
                        'note' => $zeit->note,
                        'starts_at' => $zeit->starts_at->toIso8601String(),
                        'ends_at' => $zeit->ends_at->toIso8601String(),
                    ])->values(),
                ])
                ->values(),

            // Nur die europaeischen plus UTC -- die vollstaendige Liste hat
            // ueber 400 Eintraege und hilft niemandem.
            'timezones' => collect(DateTimeZone::listIdentifiers(DateTimeZone::EUROPE))
                ->push('UTC')
                ->values(),

            'closureReasons' => collect(ClosureReason::cases())
                ->map(fn (ClosureReason $grund): array => [
                    'value' => $grund->value,
                    'label' => $grund->label(),
                ])
                ->values(),
        ]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        Location::create($request->validated());

        return back();
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($request->validated());

        return back();
    }

    /**
     * Deaktivieren statt loeschen, solange etwas daran haengt.
     *
     * Ein geloeschter Standort nimmt Arbeitszeiten, Schliesszeiten und
     * spaeter Termine mit -- ueber die Fremdschluessel mit ON DELETE CASCADE.
     */
    public function deactivate(Location $location): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $location->is_active = false;
        $location->save();

        return back();
    }

    public function activate(Location $location): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $location->is_active = true;
        $location->save();

        return back();
    }

    public function storeClosure(ClosureRequest $request, Location $location): RedirectResponse
    {
        $location->closures()->create($request->validated());

        return back();
    }

    public function destroyClosure(Location $location, LocationClosure $closure): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        abort_unless($closure->location_id === $location->getKey(), 404);

        $closure->delete();

        return back();
    }
}
