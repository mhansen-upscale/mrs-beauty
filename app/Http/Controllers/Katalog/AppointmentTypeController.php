<?php

declare(strict_types=1);

namespace App\Http\Controllers\Katalog;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Katalog\AppointmentTypeRequest;
use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\Treatment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AppointmentTypeController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Ability::ManageCatalog->value);

        return Inertia::render('katalog/Terminarten', [
            'types' => AppointmentType::query()
                ->with(['treatment', 'practitioners', 'locations'])
                ->orderBy('name')
                ->get()
                ->map(fn (AppointmentType $art): array => [
                    'uuid' => $art->uuid,
                    'name' => $art->name,
                    'slug' => $art->slug,
                    'description' => $art->description,
                    'treatment' => $art->treatment?->uuid,
                    'treatment_name' => $art->treatment?->name,
                    'duration_minutes' => $art->duration_minutes,
                    'buffer_before_minutes' => $art->buffer_before_minutes,
                    'buffer_after_minutes' => $art->buffer_after_minutes,
                    'blocked_minutes' => $art->belegteDauer(),
                    'lead_time_hours' => $art->lead_time_hours,
                    'revenue_cents' => $art->umsatzwertCents(),
                    'color' => $art->color,
                    'is_public' => $art->is_public,
                    'is_active' => $art->is_active,
                    'practitioners' => $art->practitioners->map(fn (Practitioner $b): string => (string) $b->uuid)->values(),
                    'locations' => $art->locations->map(fn (Location $s): string => (string) $s->uuid)->values(),
                ])
                ->values(),

            'treatments' => Treatment::query()->aktiv()->orderBy('name')->get()
                ->map(fn (Treatment $b): array => [
                    'uuid' => $b->uuid,
                    'name' => $b->name,
                    'avg_revenue_cents' => $b->avg_revenue_cents,
                ])->values(),

            'practitioners' => Practitioner::query()->where('is_active', true)->orderBy('last_name')->get()
                ->map(fn (Practitioner $b): array => ['uuid' => $b->uuid, 'name' => $b->name()])->values(),

            'locations' => Location::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Location $s): array => ['uuid' => $s->uuid, 'name' => $s->name])->values(),
        ]);
    }

    public function store(AppointmentTypeRequest $request): RedirectResponse
    {
        $art = AppointmentType::create([
            ...$request->safe()->except(['treatment', 'practitioners', 'locations']),
            'treatment_id' => $this->behandlungSchluessel($request->validated('treatment')),
        ]);

        $this->freigabenSetzen($art, $request);

        return back();
    }

    public function update(AppointmentTypeRequest $request, AppointmentType $appointmentType): RedirectResponse
    {
        $appointmentType->update([
            ...$request->safe()->except(['treatment', 'practitioners', 'locations']),
            'treatment_id' => $this->behandlungSchluessel($request->validated('treatment')),
        ]);

        $this->freigabenSetzen($appointmentType, $request);

        return back();
    }

    public function deactivate(AppointmentType $appointmentType): RedirectResponse
    {
        Gate::authorize(Ability::ManageCatalog->value);

        $appointmentType->is_active = false;
        $appointmentType->save();

        return back();
    }

    public function activate(AppointmentType $appointmentType): RedirectResponse
    {
        Gate::authorize(Ability::ManageCatalog->value);

        $appointmentType->is_active = true;
        $appointmentType->save();

        return back();
    }

    private function freigabenSetzen(AppointmentType $art, AppointmentTypeRequest $request): void
    {
        /** @var array<int, string> $behandler */
        $behandler = $request->validated('practitioners') ?? [];
        /** @var array<int, string> $standorte */
        $standorte = $request->validated('locations') ?? [];

        // Der Global Scope sorgt dafuer, dass hier nichts Fremdes durchkommt --
        // und der zusammengesetzte Fremdschluessel faengt ab, was doch
        // durchkaeme.
        $art->practitioners()->sync(
            $behandler === [] ? [] : Practitioner::query()->whereUuid($behandler)->pluck('id')->all()
        );

        $art->locations()->sync(
            $standorte === [] ? [] : Location::query()->whereUuid($standorte)->pluck('id')->all()
        );
    }

    private function behandlungSchluessel(mixed $uuid): ?string
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $behandlung = Treatment::query()->whereUuid($uuid)->first();

        return $behandlung instanceof Treatment ? $behandlung->getKey() : null;
    }
}
