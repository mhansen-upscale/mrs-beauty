<?php

declare(strict_types=1);

namespace App\Http\Controllers\Datenschutz;

use App\Datenschutz\Aufbewahrung;
use App\Enums\Ability;
use App\Enums\RetentionAction;
use App\Http\Controllers\Controller;
use App\Models\DataSubjectRequest;
use App\Models\RetentionPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aufbewahrungsfristen und Betroffenenrechte.
 *
 * Die Seite zeigt **immer die Vorschau**: wie viele Datensaetze ein scharfer
 * Lauf betreffen wuerde. Wer Fristen von Hand eintraegt, soll die Folge
 * sehen, bevor sie eintritt -- ein Lauf, der zu viel loescht, ist nicht
 * rueckholbar (Entscheidung C7).
 */
final class DatenschutzController extends Controller
{
    public function __construct(private readonly Aufbewahrung $aufbewahrung) {}

    public function index(TenantContext $mandant): Response
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $this->aufbewahrung->richteEin();

        $vorschau = $this->aufbewahrung->lauf(vorschau: true)->nachGegenstand();

        return Inertia::render('organisation/Datenschutz', [
            'policies' => RetentionPolicy::query()
                ->get()
                ->sortBy(fn (RetentionPolicy $regel): string => $regel->subject->label())
                ->map(fn (RetentionPolicy $regel): array => [
                    'uuid' => $regel->uuid,
                    'subject' => $regel->subject->value,
                    'subject_label' => $regel->subject->label(),
                    'retention_days' => $regel->retention_days,
                    'action' => $regel->action->value,
                    'action_label' => $regel->action->label(),
                    'is_active' => $regel->is_active,
                    'faellig' => $vorschau[$regel->subject->value] ?? 0,
                ])
                ->values(),

            'requests' => DataSubjectRequest::query()
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (DataSubjectRequest $vorgang): array => [
                    'uuid' => $vorgang->uuid,
                    'type_label' => $vorgang->type->label(),
                    'status_label' => $vorgang->status->label(),
                    'created_at' => $vorgang->created_at?->toIso8601String(),
                    'completed_at' => $vorgang->completed_at?->toIso8601String(),
                    'result' => $vorgang->result,
                ])
                ->values(),

            'actions' => collect(RetentionAction::cases())
                ->map(fn (RetentionAction $aktion): array => [
                    'value' => $aktion->value,
                    'label' => $aktion->label(),
                ])
                ->values(),

            'faellig_gesamt' => array_sum($vorschau),
        ]);
    }

    public function update(Request $request, RetentionPolicy $policy): RedirectResponse
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $daten = $request->validate([
            // Kein Wert unter einem Tag: eine Frist von null Tagen waere ein
            // Loeschlauf ueber alles, und zwar sofort.
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'action' => ['required', Rule::enum(RetentionAction::class)],
            'is_active' => ['required', 'boolean'],
        ]);

        $policy->update([
            'retention_days' => (int) $daten['retention_days'],
            'action' => RetentionAction::from((string) $daten['action']),
            'is_active' => (bool) $daten['is_active'],
        ]);

        return back();
    }

    /**
     * Setzt die Fristen jetzt durch.
     *
     * Ausdruecklich und von Hand. Der Planer laeuft taeglich **in der
     * Vorschau**; scharf schaltet ein Mensch, nachdem er die Zahlen gesehen
     * hat.
     */
    public function enforce(): RedirectResponse
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $this->aufbewahrung->lauf(vorschau: false);

        return back();
    }
}
