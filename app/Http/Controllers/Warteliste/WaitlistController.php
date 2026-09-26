<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warteliste;

use App\Enums\Ability;
use App\Enums\WaitlistOfferStatus;
use App\Enums\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Kontakte\Kontaktsuche;
use App\Models\AppointmentType;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Warteliste\Kandidatensuche;
use App\Warteliste\Klaerung;
use App\Warteliste\Wartelistenkennzahlen;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Die Warteliste im Produkt.
 *
 * **Die Kennzahlen gehoeren hierher** und nicht in eine Auswertung auf
 * Anfrage: sie sind das Verkaufsargument im Demo-Termin
 * (docs/fachlogik/warteliste.md).
 */
final class WaitlistController extends Controller
{
    public function __construct(
        private readonly Wartelistenkennzahlen $kennzahlen,
        private readonly Kandidatensuche $suche,
        private readonly Kontaktsuche $kontakte,
        private readonly Klaerung $klaerung,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Ability::ManageWaitlist->value);

        $begriff = trim((string) $request->query('kontaktsuche', ''));

        return Inertia::render('warteliste/Index', [
            'entries' => WaitlistEntry::query()
                ->with(['contact', 'appointmentType', 'locations'])
                ->orderByDesc('priority')
                ->orderBy('created_at')
                ->limit(200)
                ->get()
                ->map(fn (WaitlistEntry $eintrag): array => $this->zeile($eintrag))
                ->values(),

            'offers' => WaitlistOffer::query()
                ->with(['entry.contact'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (WaitlistOffer $angebot): array => [
                    'uuid' => $angebot->uuid,
                    'name' => $angebot->entry->contact->name(),
                    'status' => $angebot->status->value,
                    'statusLabel' => $angebot->status->label(),
                    'ausloeser' => $angebot->trigger->label(),
                    'beginn' => $angebot->starts_at->toIso8601String(),
                    'wann' => $angebot->created_at?->toIso8601String(),
                ])
                ->values(),

            // **Ausloeser 3**: parallel angeboten, zugesagt -- und jetzt muss
            // ein Mensch entscheiden, wem der Slot gehoert.
            'klaerungen' => $this->klaerung->offene()
                ->map(fn (WaitlistOffer $angebot): array => [
                    'uuid' => $angebot->uuid,
                    'name' => $angebot->entry->contact->name(),
                    'bisher' => $this->klaerung->wackeligerTermin($angebot)?->contact?->name(),
                    'beginn' => $angebot->starts_at->toIso8601String(),
                    'zugesagt' => $angebot->answered_at?->toIso8601String(),
                ])
                ->values(),

            'metrics' => $this->kennzahlen->fuerMonat(),
            'grenze' => $this->suche->grenze(),

            'appointmentTypes' => AppointmentType::query()
                ->aktiv()
                ->orderBy('name')
                ->get()
                ->map(fn (AppointmentType $art): array => ['uuid' => $art->uuid, 'name' => $art->name])
                ->values(),

            'locations' => Location::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Location $ort): array => ['uuid' => $ort->uuid, 'name' => $ort->name])
                ->values(),

            'practitioners' => Practitioner::query()
                ->where('is_active', true)
                ->orderBy('last_name')
                ->get()
                ->map(fn (Practitioner $person): array => ['uuid' => $person->uuid, 'name' => $person->name()])
                ->values(),

            'kontaktsuche' => $begriff === '' ? [] : $this->kontakte->suche($begriff)
                ->map(fn (Contact $kontakt): array => ['uuid' => $kontakt->uuid, 'name' => $kontakt->name()])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageWaitlist->value);

        $daten = $request->validate([
            'contact' => ['required', 'string'],
            'appointment_type' => ['required', 'string'],
            'practitioner' => ['nullable', 'string'],
            'locations' => ['array'],
            'locations.*' => ['string'],
            'earliest_date' => ['required', 'date'],
            'latest_date' => ['required', 'date', 'after_or_equal:earliest_date'],
            'weekday_mask' => ['required', 'integer', 'between:1,127'],
            'time_windows' => ['array'],
            'time_windows.*.von' => ['required', 'date_format:H:i'],
            'time_windows.*.bis' => ['required', 'date_format:H:i'],
            // **K8**: das Feld, an dem die Warteliste steht und faellt.
            'min_notice_hours' => ['required', 'integer', 'between:0,336'],
            'priority' => ['integer', 'between:0,9'],
            'expires_at' => ['required', 'date', 'after:today'],
        ]);

        $kontakt = Contact::query()->whereUuid($daten['contact'])->firstOrFail();
        $art = AppointmentType::query()->whereUuid($daten['appointment_type'])->firstOrFail();

        $behandler = is_string($daten['practitioner'] ?? null) && $daten['practitioner'] !== ''
            ? Practitioner::query()->whereUuid($daten['practitioner'])->first()
            : null;

        /** @var list<string> $standorte */
        $standorte = $daten['locations'] ?? [];

        $eintrag = new WaitlistEntry;
        $eintrag->contact_id = $kontakt->getKey();
        $eintrag->appointment_type_id = $art->getKey();
        $eintrag->practitioner_id = $behandler?->getKey();
        $eintrag->status = WaitlistStatus::Active;
        $eintrag->all_locations = $standorte === [];
        $eintrag->earliest_date = CarbonImmutable::parse($daten['earliest_date']);
        $eintrag->latest_date = CarbonImmutable::parse($daten['latest_date']);
        $eintrag->weekday_mask = (int) $daten['weekday_mask'];
        $eintrag->time_windows = $daten['time_windows'] ?? null;
        $eintrag->min_notice_hours = (int) $daten['min_notice_hours'];
        $eintrag->priority = (int) ($daten['priority'] ?? 0);
        $eintrag->expires_at = CarbonImmutable::parse($daten['expires_at']);
        $eintrag->save();

        if ($standorte !== []) {
            $eintrag->locations()->sync(
                Location::query()->whereIn('id', Location::query()->whereUuidIn($standorte)->pluck('id'))->pluck('id')->all()
            );
        }

        return back();
    }

    /** Ausloeser 3: der Slot geht an die Wartende, der bisherige Termin wird abgesagt. */
    public function uebergeben(WaitlistOffer $offer): RedirectResponse
    {
        Gate::authorize(Ability::ManageWaitlist->value);

        try {
            $this->klaerung->uebergib($offer);
        } catch (RuntimeException $fehler) {
            // Auch "nicht buchbar" und "Slot nicht verfuegbar" -- beide sind
            // RuntimeExceptions und gehoeren als Satz an die Seite.
            return back()->withErrors(['klaerung' => $fehler->getMessage()]);
        }

        return back();
    }

    /** Ausloeser 3: der bisherige Termin bleibt, die Wartende wartet weiter. */
    public function behalten(WaitlistOffer $offer): RedirectResponse
    {
        Gate::authorize(Ability::ManageWaitlist->value);

        try {
            $this->klaerung->behalte($offer);
        } catch (RuntimeException $fehler) {
            return back()->withErrors(['klaerung' => $fehler->getMessage()]);
        }

        return back();
    }

    /** Die Prioritaet ist von Hand erhoehbar -- und im Protokoll sichtbar. */
    public function update(Request $request, WaitlistEntry $entry): RedirectResponse
    {
        Gate::authorize(Ability::ManageWaitlist->value);

        $daten = $request->validate([
            'priority' => ['required', 'integer', 'between:0,9'],
        ]);

        $entry->priority = (int) $daten['priority'];
        $entry->save();

        return back();
    }

    public function destroy(WaitlistEntry $entry): RedirectResponse
    {
        Gate::authorize(Ability::ManageWaitlist->value);

        // Offene Angebote zuerst: ein Angebot ohne Eintrag waere ein Hold,
        // den niemand mehr freigibt.
        $entry->offers()->offen()->get()->each(function (WaitlistOffer $angebot): void {
            $angebot->status = WaitlistOfferStatus::Superseded;
            $angebot->save();
        });

        $entry->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function zeile(WaitlistEntry $eintrag): array
    {
        $art = $eintrag->appointmentType;

        return [
            'uuid' => $eintrag->uuid,
            'name' => $eintrag->contact->name(),
            'behandlung' => $art instanceof AppointmentType ? $art->name : '—',
            'status' => $eintrag->status->value,
            'statusLabel' => $eintrag->status->label(),
            'prioritaet' => $eintrag->priority,
            'vorlauf' => $eintrag->min_notice_hours,
            'von' => $eintrag->earliest_date->toDateString(),
            'bis' => $eintrag->latest_date->toDateString(),
            'wochentage' => $eintrag->weekday_mask,
            'zeitfenster' => $eintrag->time_windows ?? [],
            'standorte' => $eintrag->all_locations
                ? []
                : $eintrag->locations->map(fn (Location $ort): string => $ort->name)->values(),
            'alleStandorte' => $eintrag->all_locations,
            'angebote' => $eintrag->offers_sent_count,
            'laeuftAb' => $eintrag->expires_at->toIso8601String(),
            // K11 sichtbar machen: ohne Einwilligung geht nichts hinaus, und
            // das soll niemand raten muessen.
            'erreichbar' => $this->suche->darfAngeschriebenWerden($eintrag),
        ];
    }
}
