<?php

declare(strict_types=1);

namespace App\Http\Controllers\Termine;

use App\Enums\Ability;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancellationReason;
use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Termine\AbsageRequest;
use App\Http\Requests\Termine\StatusRequest;
use App\Http\Requests\Termine\TerminRequest;
use App\Http\Requests\Termine\VerschiebenRequest;
use App\Kontakte\Kontaktsuche;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentType;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\WorkingHour;
use App\Support\Uuid;
use App\Termine\NichtBuchbar;
use App\Termine\Statusautomat;
use App\Termine\TerminNichtAenderbar;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotNichtVerfuegbar;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

/**
 * Die interne Terminverwaltung.
 *
 * Die Vorschlagsliste kommt ueber einen Inertia-Teilnachladevorgang und nicht
 * ueber eine eigene JSON-Route: Entscheidung S2 schliesst eine eigene
 * API-Schicht fuer das eigene Frontend aus.
 */
final class AppointmentController extends Controller
{
    public function __construct(
        private readonly Terminplaner $planer,
        private readonly Verfuegbarkeit $verfuegbarkeit,
        private readonly Kontaktsuche $kontaktsuche,
        private readonly Statusautomat $statusautomat,
    ) {}

    public function index(Request $request): Response
    {
        $darfAendern = Gate::allows(Ability::ManageAppointments->value);

        if (! $darfAendern) {
            Gate::authorize(Ability::ViewOwnCalendar->value);
        }

        $standorte = Location::query()->where('is_active', true)->orderBy('name')->get();
        $standort = $this->gewaehlterStandort($request, $standorte);

        if (! $standort instanceof Location) {
            return Inertia::render('termine/Index', [
                'locations' => [],
                'practitioners' => [],
                'appointmentTypes' => [],
                'appointments' => [],
                'date' => CarbonImmutable::now()->toDateString(),
                'view' => 'tag',
                'days' => [],
                'hours' => ['from' => 8, 'to' => 18],
                'location' => null,
                'canManage' => $darfAendern,
            ]);
        }

        // **Die eine Stelle, an der Ortszeit nach UTC gerechnet wird.** Ein
        // Mensch waehlt einen Kalendertag, keinen UTC-Zeitraum. Mitternacht
        // ist in den unterstuetzten Zonen eindeutig -- umgestellt wird um
        // zwei Uhr.
        $zone = $standort->zone();
        $datum = $this->gewaehltesDatum($request, $standort);
        $mitternacht = CarbonImmutable::parse($datum.' 00:00:00', $zone);

        // Der Tag -- fuer die Vorschlaege immer, auch in der Wochenansicht:
        // eine Woche voller freier Startzeiten waere eine Rechnung, die
        // niemand angefordert hat. Tage werden in der Ortszeit addiert, damit
        // der Tag der Zeitumstellung 23 oder 25 Stunden hat und nicht 24.
        $tagVon = $mitternacht->utc();
        $tagBis = $mitternacht->addDay()->utc();

        // **Die Woche ist eine Woche der Praxis** (offen seit WP-11): Montag
        // 00:00 bis Montag 00:00 Ortszeit. Wer in UTC schneidet, verliert den
        // Sonntagabend oder bekommt den naechsten Montag dazu.
        $ansicht = $request->string('ansicht')->toString() === 'woche' ? 'woche' : 'tag';
        $montag = $mitternacht->startOfWeek(CarbonInterface::MONDAY);

        [$von, $bis] = $ansicht === 'woche'
            ? [$montag->utc(), $montag->addDays(7)->utc()]
            : [$tagVon, $tagBis];

        $tage = $ansicht === 'woche'
            ? array_map(fn (int $versatz): string => $montag->addDays($versatz)->toDateString(), range(0, 6))
            : [$datum];

        $eigener = $darfAendern ? null : $this->eigenerBehandler($request);

        $behandler = Practitioner::query()
            ->where('is_active', true)
            ->whereHas('locations', fn ($abfrage) => $abfrage->whereKey($standort->getKey()))
            ->when($eigener instanceof Practitioner, fn ($abfrage) => $abfrage->whereKey($eigener?->getKey()))
            ->orderBy('last_name')
            ->get();

        /** @var array<string, int> $farben */
        $farben = [];

        foreach ($behandler->values() as $stelle => $person) {
            $farben[bin2hex((string) $person->getKey())] = $stelle % 8 + 1;
        }

        $termine = Appointment::query()
            ->aktiv()
            ->with(['contact', 'appointmentType', 'practitioner', 'notifications'])
            ->where('location_id', $standort->getKey())
            ->whereIn('practitioner_id', $behandler->modelKeys())
            ->where('blocked_from', '<', $bis)
            ->where('blocked_until', '>', $von)
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('termine/Index', [
            'date' => $datum,
            'view' => $ansicht,
            'days' => $tage,
            // Das Zeitraster: von der fruehesten Arbeitszeit bis zur
            // spaetesten, und so weit darueber hinaus, wie ein Termin reicht.
            'hours' => $this->raster($standort, $behandler, $termine),
            'canManage' => $darfAendern,

            'location' => [
                'uuid' => $standort->uuid,
                'name' => $standort->name,
                'timezone' => $standort->timezone,
            ],

            'locations' => $standorte
                ->map(fn (Location $ort): array => ['uuid' => $ort->uuid, 'name' => $ort->name])
                ->values(),

            // Die Kalenderfarbe haengt am Behandler, nicht an der Terminart:
            // die Flaeche gehoert dem Behandler (docs/design/farben.md). Acht
            // Toene, danach wird die Reihe wiederholt -- weitere
            // unterscheidbare Toene in dieser Helligkeit gibt es nicht.
            'practitioners' => $behandler
                ->values()
                ->map(fn (Practitioner $person, int $stelle): array => [
                    'uuid' => $person->uuid,
                    'name' => $person->name(),
                    'color_index' => $stelle % 8 + 1,
                ])
                ->values(),

            // Auch die nicht oeffentlichen: die interne Verwaltung sieht mehr
            // als die Buchungsseite (docs/fachlogik/verfuegbarkeit.md).
            'appointmentTypes' => AppointmentType::query()->aktiv()->orderBy('name')->get()
                ->map(fn (AppointmentType $art): array => [
                    'uuid' => $art->uuid,
                    'name' => $art->name,
                    'duration_minutes' => $art->duration_minutes,
                    'blocked_minutes' => $art->belegteDauer(),
                    'color' => $art->color,
                    'is_public' => $art->is_public,
                ])
                ->values(),

            'appointments' => $termine
                ->map(fn (Appointment $termin): array => $this->darstellung(
                    $termin,
                    $standort,
                    CarbonImmutable::now(),
                    $farben[bin2hex((string) $termin->getAttribute('practitioner_id'))] ?? 1,
                ))
                ->values(),

            'statuses' => collect(AppointmentStatus::cases())
                ->map(fn (AppointmentStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values(),

            // Pflichtfeld beim Anlegen (attribution.md, Testfall 6).
            'sources' => collect(LeadSource::cases())
                ->map(fn (LeadSource $quelle): array => [
                    'value' => $quelle->value,
                    'label' => $quelle->label(),
                ])
                ->values(),

            'reasons' => collect(CancellationReason::cases())
                ->map(fn (CancellationReason $grund): array => [
                    'value' => $grund->value,
                    'label' => $grund->label(),
                ])
                ->values(),

            // Erst auf Anforderung berechnet -- ein Teilnachladevorgang von
            // Inertia, keine eigene API-Route (Entscheidung S2).
            'proposals' => Inertia::optional(fn (): array => $this->vorschlaege($request, $standort, $tagVon, $tagBis)),
            'contacts' => Inertia::optional(fn (): array => $this->kontakte($request)),
        ]);
    }

    public function store(TerminRequest $request): RedirectResponse
    {
        $daten = $request->validated();
        $vorschlag = $this->vorschlagAus($daten);

        $kontakt = $this->kontaktAus($daten);

        $this->fachlich(fn () => $this->planer->buche(
            $vorschlag,
            $kontakt,
            BookingChannel::Internal,
            AppointmentStatus::Confirmed,
            (bool) ($daten['uebersteuern'] ?? false),
            quelle: LeadSource::from((string) $daten['quelle']),
        ));

        return back();
    }

    public function reschedule(VerschiebenRequest $request, Appointment $appointment): RedirectResponse
    {
        $daten = $request->validated();

        $vorschlag = $this->vorschlagAus([
            ...$daten,
            'appointment_type' => (string) $appointment->appointmentType->uuid,
        ]);

        $this->fachlich(fn () => $this->planer->verschiebe(
            $appointment,
            $vorschlag,
            (bool) ($daten['uebersteuern'] ?? false),
        ));

        return back();
    }

    public function status(StatusRequest $request, Appointment $appointment): RedirectResponse
    {
        $status = AppointmentStatus::from((string) $request->validated('status'));

        $this->fachlich(fn () => $this->planer->setzeStatus($appointment, $status));

        return back();
    }

    public function cancel(AbsageRequest $request, Appointment $appointment): RedirectResponse
    {
        $grund = CancellationReason::from((string) $request->validated('reason'));

        $this->fachlich(fn () => $this->planer->sageAb($appointment, $grund));

        return back();
    }

    /**
     * Uebersetzt die fachlichen Ausnahmen in eine Formularmeldung.
     *
     * Die Meldungen sind fuer Menschen geschrieben -- "Zu dieser Zeit
     * arbeitet der Behandler nicht an diesem Standort" statt eines
     * Fehlercodes.
     */
    private function fachlich(callable $vorgang): void
    {
        try {
            $vorgang();
        } catch (NichtBuchbar|SlotNichtVerfuegbar|TerminNichtAenderbar|InvalidArgumentException $ausnahme) {
            throw ValidationException::withMessages(['blocked_from' => $ausnahme->getMessage()]);
        } catch (Throwable $ausnahme) {
            report($ausnahme);

            throw ValidationException::withMessages([
                'blocked_from' => 'Der Termin ließ sich nicht speichern. Bitte noch einmal versuchen.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function vorschlagAus(array $daten): Slotvorschlag
    {
        $art = AppointmentType::query()->whereUuid((string) $daten['appointment_type'])->firstOrFail();
        $behandler = Practitioner::query()->whereUuid((string) $daten['practitioner'])->firstOrFail();
        $standort = Location::query()->whereUuid((string) $daten['location'])->firstOrFail();

        return Slotvorschlag::ab(
            $art,
            $behandler,
            $standort,
            CarbonImmutable::parse((string) $daten['blocked_from'])->utc(),
        );
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function kontaktAus(array $daten): Contact
    {
        $uuid = $daten['contact'] ?? null;

        if (is_string($uuid) && $uuid !== '') {
            return Contact::query()->whereUuid($uuid)->firstOrFail();
        }

        return $this->kontaktsuche->findeOderLege([
            'first_name' => (string) $daten['first_name'],
            'last_name' => (string) $daten['last_name'],
            'email' => is_string($daten['email'] ?? null) ? (string) $daten['email'] : null,
            'phone' => is_string($daten['phone'] ?? null) ? (string) $daten['phone'] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function darstellung(Appointment $termin, Location $standort, CarbonImmutable $jetzt, int $farbe): array
    {
        return [
            'uuid' => $termin->uuid,
            // Der Tag in Ortszeit -- die Wochenansicht sortiert danach.
            'date' => $standort->ortszeit($termin->starts_at)->toDateString(),
            'color_index' => $farbe,
            'practitioner' => $termin->practitioner->uuid,
            'practitioner_name' => $termin->practitioner->name(),
            'contact_name' => $termin->contact->name(),
            'contact' => $termin->contact->uuid,
            'type_name' => $termin->appointmentType->name,
            'type' => $termin->appointmentType->uuid,
            'status' => $termin->status->value,
            'status_label' => $termin->status->label(),
            'booked_via' => $termin->booked_via->label(),
            'is_override' => $termin->is_override,

            // Angezeigte Zeit und belegte Zeit sind zwei Groessen. Die
            // Oberflaeche zeigt beide -- der Block im Kalender ist laenger
            // als der Termin, und das soll man sehen.
            'starts_at' => $standort->ortszeit($termin->starts_at)->format('H:i'),
            'ends_at' => $standort->ortszeit($termin->ends_at)->format('H:i'),
            'blocked_from' => $standort->ortszeit($termin->blocked_from)->format('H:i'),
            'blocked_until' => $standort->ortszeit($termin->blocked_until)->format('H:i'),

            // Nur, was jetzt auch wirklich geht. "Erschienen" fuer einen
            // Termin in der naechsten Woche waere eine Schaltflaeche, die der
            // Server gleich darauf ablehnt.
            'next_statuses' => collect($this->statusautomat->moeglichFuer($termin, $jetzt))
                ->reject(fn (AppointmentStatus $status): bool => $status === AppointmentStatus::Cancelled)
                ->map(fn (AppointmentStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values(),

            // Was an den Kontakt rausging und was noch aussteht. Ohne diese
            // Anzeige verschwindet "diese Person bekommt keine Erinnerung"
            // lautlos -- und faellt erst auf, wenn jemand nicht erscheint.
            'notifications' => $termin->notifications
                ->sortBy(fn (AppointmentNotification $zeile): string => $zeile->kind->value)
                ->map(fn (AppointmentNotification $zeile): array => [
                    'label' => $zeile->kind->label(),
                    'state' => match (true) {
                        $zeile->sent_at !== null => 'verschickt',
                        $zeile->failed_at !== null => 'fehlgeschlagen',
                        default => 'geplant',
                    },
                    'detail' => match (true) {
                        $zeile->sent_at !== null => $standort->ortszeit($zeile->sent_at)->format('d.m.Y, H:i'),
                        $zeile->failed_at !== null => $zeile->failure === 'no_channel'
                            ? 'kein Kontaktweg hinterlegt'
                            : (string) $zeile->failure,
                        default => $zeile->scheduled_for === null
                            ? 'steht aus'
                            : $standort->ortszeit($zeile->scheduled_for)->format('d.m.Y, H:i'),
                    },
                ])
                ->values(),

            'can_reschedule' => $termin->istAenderbar(),
            'can_cancel' => $termin->istAenderbar(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vorschlaege(Request $request, Location $standort, CarbonImmutable $von, CarbonImmutable $bis): array
    {
        $artUuid = $request->string('type')->toString();

        if (! Uuid::isCanonical($artUuid)) {
            return [];
        }

        $art = AppointmentType::query()->whereUuid($artUuid)->first();

        if (! $art instanceof AppointmentType) {
            return [];
        }

        $behandlerUuid = $request->string('practitioner')->toString();
        $behandler = Uuid::isCanonical($behandlerUuid)
            ? Practitioner::query()->whereUuid($behandlerUuid)->first()
            : null;

        return collect($this->verfuegbarkeit->freieStartzeiten(
            art: $art,
            von: $von,
            bis: $bis,
            nurBehandler: $behandler,
            nurStandort: $standort,
        ))->map(fn (Slotvorschlag $vorschlag): array => [
            ...$vorschlag->toArray(),
            // Der Beginn der **belegten** Strecke. Er geht zurueck an den
            // Server; wer stattdessen die angezeigte Zeit schickte,
            // verschoebe jeden Termin um die Ruestzeit davor.
            'blocked_from' => $vorschlag->blockedFrom->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function kontakte(Request $request): array
    {
        return $this->kontaktsuche
            ->suche($request->string('search')->toString())
            ->map(fn (Contact $kontakt): array => [
                'uuid' => $kontakt->uuid,
                'name' => $kontakt->name(),
                'email' => $kontakt->email,
                'phone' => $kontakt->phone,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Location>  $standorte
     */
    private function gewaehlterStandort(Request $request, $standorte): ?Location
    {
        $uuid = $request->string('location')->toString();

        if (Uuid::isCanonical($uuid)) {
            $gewaehlt = $standorte->first(fn (Location $ort): bool => $ort->uuid === $uuid);

            if ($gewaehlt instanceof Location) {
                return $gewaehlt;
            }
        }

        return $standorte->first();
    }

    private function gewaehltesDatum(Request $request, Location $standort): string
    {
        $datum = $request->string('date')->toString();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) === 1) {
            return $datum;
        }

        return $standort->ortszeit(CarbonImmutable::now())->toDateString();
    }

    /**
     * Von welcher bis zu welcher vollen Stunde das Raster reicht.
     *
     * Aus den Arbeitszeiten der gezeigten Behandler an diesem Standort -- und
     * weiter, wenn ein Termin darueber hinausgeht: ein uebersteuerter Termin
     * um 19:30 muss sichtbar sein, auch wenn um 18 Uhr Feierabend ist.
     *
     * @param  EloquentCollection<int, Practitioner>  $behandler
     * @param  EloquentCollection<int, Appointment>  $termine
     * @return array{from: int, to: int}
     */
    private function raster(Location $standort, EloquentCollection $behandler, EloquentCollection $termine): array
    {
        $zeiten = WorkingHour::query()
            ->where('location_id', $standort->getKey())
            ->whereIn('practitioner_id', $behandler->modelKeys())
            ->get();

        $minuten = [];

        foreach ($zeiten as $zeit) {
            $minuten[] = $this->minuten($zeit->starts_at);
            $minuten[] = $this->minuten($zeit->ends_at);
        }

        foreach ($termine as $termin) {
            $minuten[] = $this->minuten($standort->ortszeit($termin->blocked_from)->format('H:i'));

            $ende = $standort->ortszeit($termin->blocked_until);
            $minuten[] = $ende->toDateString() !== $standort->ortszeit($termin->blocked_from)->toDateString()
                ? 24 * 60
                : $this->minuten($ende->format('H:i'));
        }

        if ($minuten === []) {
            return ['from' => 8, 'to' => 18];
        }

        return [
            'from' => max(0, intdiv(min($minuten), 60)),
            'to' => min(24, (int) ceil(max($minuten) / 60)),
        ];
    }

    private function minuten(string $uhrzeit): int
    {
        [$stunde, $minute] = array_map(intval(...), explode(':', $uhrzeit) + [0, 0]);

        return $stunde * 60 + $minute;
    }

    private function eigenerBehandler(Request $request): ?Practitioner
    {
        $benutzer = $request->user();

        if ($benutzer === null) {
            return null;
        }

        return Practitioner::query()->where('user_id', $benutzer->getKey())->first();
    }
}
