<?php

declare(strict_types=1);

namespace App\Http\Controllers\Leads;

use App\Enums\Ability;
use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Leads\Leadverwaltung;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Treatment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Anfragen: sehen, beantworten, abschliessen.
 *
 * Der Trichter oben ist keine Verzierung. `docs/fachlogik/attribution.md`
 * legt die Kennzahlen einheitlich fest, weil "abweichende Auslegung in
 * Berichten der schnellste Weg ist, Vertrauen in die Zahlen zu verlieren" --
 * diese Seite zeigt sie so, wie sie dort definiert sind.
 */
final class LeadController extends Controller
{
    public function __construct(private readonly Leadverwaltung $leads) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Ability::ManageContacts->value);

        $filter = LeadStatus::tryFrom((string) $request->query('status', ''));

        return Inertia::render('leads/Index', [
            'status' => $filter?->value,

            'leads' => Lead::query()
                ->with(['contact', 'treatment'])
                ->when($filter instanceof LeadStatus, fn ($abfrage) => $abfrage->where('status', $filter?->value))
                // Offene zuerst, darin die aeltesten: was am laengsten
                // liegenbleibt, gehoert nach oben.
                ->orderByRaw("field(status, 'new', 'contacted', 'scheduled', 'won', 'lost')")
                ->orderBy('last_activity_at')
                ->limit(200)
                ->get()
                ->map(fn (Lead $lead): array => [
                    'uuid' => $lead->uuid,
                    'contact' => $lead->contact->name(),
                    'contact_uuid' => $lead->contact->uuid,
                    'treatment' => $lead->treatment?->name,
                    'status' => $lead->status->value,
                    'status_label' => $lead->status->label(),
                    'open' => $lead->status->istOffen(),
                    'source_label' => $lead->source->label(),
                    'lost_reason_label' => $lead->lost_reason?->label(),
                    'first_response_seconds' => $lead->first_response_seconds,
                    'created_at' => $lead->created_at?->toIso8601String(),
                    'last_activity_at' => $lead->last_activity_at->toIso8601String(),
                ])
                ->values(),

            'funnel' => $this->trichter(),
            'speed_to_lead' => $this->speedToLead(),

            'statuses' => collect(LeadStatus::cases())
                ->map(fn (LeadStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values(),

            'sources' => collect(LeadSource::cases())
                ->map(fn (LeadSource $quelle): array => [
                    'value' => $quelle->value,
                    'label' => $quelle->label(),
                ])
                ->values(),

            'lost_reasons' => collect(LeadLostReason::cases())
                ->map(fn (LeadLostReason $grund): array => [
                    'value' => $grund->value,
                    'label' => $grund->label(),
                ])
                ->values(),

            'treatments' => Treatment::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Treatment $behandlung): array => [
                    'uuid' => $behandlung->uuid,
                    'name' => $behandlung->name,
                ])
                ->values(),
        ]);
    }

    /**
     * Eine Anfrage von Hand aufnehmen -- der Anruf, der gerade hereinkam.
     *
     * Ob daraus ein neuer Vorgang wird, entscheidet die Leadverwaltung
     * (Entscheidung D4) und nicht dieser Controller.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $daten = $request->validate([
            'contact' => ['required', 'uuid'],
            'treatment' => ['nullable', 'uuid'],
            'source' => ['required', Rule::enum(LeadSource::class)],
        ]);

        $kontakt = Contact::query()->whereUuid((string) $daten['contact'])->firstOrFail();

        $behandlung = is_string($daten['treatment'] ?? null)
            ? Treatment::query()->whereUuid((string) $daten['treatment'])->first()
            : null;

        $this->leads->erfasse($kontakt, $behandlung, LeadSource::from((string) $daten['source']));

        return back();
    }

    public function respond(Lead $lead): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $this->leads->vermerkeReaktion($lead);

        return back();
    }

    public function lose(Request $request, Lead $lead): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $daten = $request->validate([
            'reason' => ['required', Rule::enum(LeadLostReason::class)],
        ]);

        $this->leads->gibAuf($lead, LeadLostReason::from((string) $daten['reason']));

        return back();
    }

    /**
     * Der Trichter -- eine Zahl je Stufe, jede Anfrage einmal gezaehlt.
     *
     * @return array<string, int>
     */
    private function trichter(): array
    {
        $gezaehlt = Lead::query()
            ->selectRaw('status, count(*) as anzahl')
            ->groupBy('status')
            ->pluck('anzahl', 'status');

        $trichter = [];

        foreach (LeadStatus::cases() as $status) {
            $trichter[$status->value] = (int) $gezaehlt->get($status->value, 0);
        }

        return $trichter;
    }

    /**
     * Speed-to-Lead: der **Median**, nicht der Mittelwert.
     *
     * Ein einzelner Vorgang, den jemand nach drei Wochen anfasst, zoege einen
     * Mittelwert so weit hoch, dass die Zahl nichts mehr aussagt.
     * `docs/fachlogik/attribution.md` legt den Median fest.
     */
    private function speedToLead(): ?int
    {
        /** @var array<int, int> $werte */
        $werte = Lead::query()
            ->whereNotNull('first_response_seconds')
            ->orderBy('first_response_seconds')
            ->pluck('first_response_seconds')
            ->map(fn (mixed $wert): int => (int) $wert)
            ->all();

        $anzahl = count($werte);

        if ($anzahl === 0) {
            return null;
        }

        $mitte = intdiv($anzahl, 2);

        return $anzahl % 2 === 1
            ? $werte[$mitte]
            : (int) round(($werte[$mitte - 1] + $werte[$mitte]) / 2);
    }
}
