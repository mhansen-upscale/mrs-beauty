<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stammdaten;

use App\Enums\Ability;
use App\Enums\AbsenceReason;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stammdaten\AbsenceRequest;
use App\Http\Requests\Stammdaten\WorkingHourRequest;
use App\Models\Absence;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class PractitionerController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Ability::ManageMasterData->value);

        return Inertia::render('stammdaten/Behandler', [
            'practitioners' => Practitioner::query()
                // 'user' und 'workingHours.location' muessen mit: beides wird
                // unten fuer die uuid gebraucht, und strenge Modelle lassen
                // kein Nachladen zu -- was gut ist, es waere ein N+1.
                ->with([
                    'locations',
                    'user',
                    'workingHours.location',
                    'absences' => fn ($a) => $a->orderBy('starts_at'),
                ])
                ->orderBy('last_name')
                ->get()
                ->map(fn (Practitioner $behandler): array => [
                    'uuid' => $behandler->uuid,
                    'title' => $behandler->title,
                    'first_name' => $behandler->first_name,
                    'last_name' => $behandler->last_name,
                    'name' => $behandler->name(),
                    'is_active' => $behandler->is_active,
                    'avatar_url' => $behandler->avatarUrl(),
                    'initials' => $behandler->initialen(),
                    'user' => $behandler->user?->uuid,
                    'locations' => $behandler->locations->map(fn (Location $s): string => (string) $s->uuid)->values(),
                    'working_hours' => $behandler->workingHours->map(fn (WorkingHour $zeit): array => [
                        'uuid' => $zeit->uuid,
                        'location' => $zeit->location?->uuid,
                        'weekday' => $zeit->weekday->value,
                        'weekday_label' => $zeit->weekday->label(),
                        'starts_at' => mb_substr($zeit->starts_at, 0, 5),
                        'ends_at' => mb_substr($zeit->ends_at, 0, 5),
                    ])->values(),
                    'absences' => $behandler->absences->map(fn (Absence $abwesend): array => [
                        'uuid' => $abwesend->uuid,
                        'reason' => $abwesend->reason->value,
                        'reason_label' => $abwesend->reason->label(),
                        'note' => $abwesend->note,
                        'starts_at' => $abwesend->starts_at->toIso8601String(),
                        'ends_at' => $abwesend->ends_at->toIso8601String(),
                    ])->values(),
                ])
                ->values(),

            'locations' => Location::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Location $s): array => [
                    'uuid' => $s->uuid,
                    'name' => $s->name,
                    'timezone' => $s->timezone,
                ])
                ->values(),

            'weekdays' => collect(Weekday::cases())
                ->map(fn (Weekday $tag): array => ['value' => $tag->value, 'label' => $tag->label()])
                ->values(),

            'absenceReasons' => collect(AbsenceReason::cases())
                ->map(fn (AbsenceReason $grund): array => ['value' => $grund->value, 'label' => $grund->label()])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $validiert = $this->validiere($request);

        $behandler = Practitioner::create([
            'title' => $validiert['title'] ?? null,
            'first_name' => $validiert['first_name'],
            'last_name' => $validiert['last_name'],
            'user_id' => $this->kontoSchluessel($validiert['user'] ?? null),
        ]);

        $behandler->locations()->sync($this->standortSchluessel($validiert['locations'] ?? []));

        return back();
    }

    public function update(Request $request, Practitioner $practitioner): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $validiert = $this->validiere($request);

        $practitioner->update([
            'title' => $validiert['title'] ?? null,
            'first_name' => $validiert['first_name'],
            'last_name' => $validiert['last_name'],
            'user_id' => $this->kontoSchluessel($validiert['user'] ?? null),
        ]);

        $practitioner->locations()->sync($this->standortSchluessel($validiert['locations'] ?? []));

        return back();
    }

    public function deactivate(Practitioner $practitioner): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $practitioner->is_active = false;
        $practitioner->save();

        return back();
    }

    public function activate(Practitioner $practitioner): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $practitioner->is_active = true;
        $practitioner->save();

        return back();
    }

    /**
     * Ein Bild fuer die Buchungsseite.
     *
     * **Auf einer oeffentlichen Platte, unverschluesselt** -- dieselbe
     * Entscheidung wie beim Namen: die Praxis veroeffentlicht es selbst. Ein
     * Bild, das bei jedem Aufruf entschluesselt werden muesste, waere auf
     * einer oeffentlichen Seite auch nicht zu bezahlen.
     *
     * Das alte wird entfernt, nicht liegengelassen: sonst sammelt sich auf
     * dem Speicher jedes je hochgeladene Portraet.
     */
    public function storeAvatar(Request $request, Practitioner $practitioner): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=200,min_height=200'],
        ]);

        $datei = $request->file('avatar');

        if (! $datei instanceof UploadedFile) {
            return back();
        }

        $alt = $practitioner->avatar_path;

        $pfad = $datei->store('behandler', 'public');

        if (! is_string($pfad)) {
            return back()->withErrors(['avatar' => 'Das Bild konnte nicht gespeichert werden.']);
        }

        $practitioner->avatar_path = $pfad;
        $practitioner->save();

        if (is_string($alt) && $alt !== '') {
            Storage::disk('public')->delete($alt);
        }

        return back();
    }

    public function destroyAvatar(Practitioner $practitioner): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        if (is_string($practitioner->avatar_path) && $practitioner->avatar_path !== '') {
            Storage::disk('public')->delete($practitioner->avatar_path);
        }

        $practitioner->avatar_path = null;
        $practitioner->save();

        return back();
    }

    public function storeWorkingHour(WorkingHourRequest $request, Practitioner $practitioner): RedirectResponse
    {
        $standort = Location::query()->whereUuid((string) $request->validated('location'))->firstOrFail();

        $practitioner->workingHours()->create([
            'location_id' => $standort->getKey(),
            'weekday' => (int) $request->validated('weekday'),
            'starts_at' => $request->uhrzeit('starts_at'),
            'ends_at' => $request->uhrzeit('ends_at'),
        ]);

        return back();
    }

    public function destroyWorkingHour(Practitioner $practitioner, WorkingHour $workingHour): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        abort_unless($workingHour->practitioner_id === $practitioner->getKey(), 404);

        $workingHour->delete();

        return back();
    }

    public function storeAbsence(AbsenceRequest $request, Practitioner $practitioner): RedirectResponse
    {
        $practitioner->absences()->create($request->validated());

        return back();
    }

    public function destroyAbsence(Practitioner $practitioner, Absence $absence): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        abort_unless($absence->practitioner_id === $practitioner->getKey(), 404);

        $absence->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validiere(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:64'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'user' => ['nullable', 'string', 'uuid'],
            'locations' => ['array'],
            'locations.*' => ['string', 'uuid'],
        ]);
    }

    /**
     * Der Global Scope sorgt dafuer, dass ein Konto einer fremden
     * Organisation hier nicht auftaucht -- users ist allerdings kein
     * TenantModel, deshalb die ausdrueckliche Einschraenkung.
     */
    private function kontoSchluessel(?string $uuid): ?string
    {
        if ($uuid === null) {
            return null;
        }

        $benutzer = User::query()->derOrganisation()->whereUuid($uuid)->first();

        return $benutzer instanceof User ? $benutzer->getKey() : null;
    }

    /**
     * @param  array<int, string>  $uuids
     * @return array<int, string>
     */
    private function standortSchluessel(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return Location::query()->whereUuid($uuids)->pluck('id')->all();
    }
}
