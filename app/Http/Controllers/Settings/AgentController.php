<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Agent\Guardrails\Schutz;
use App\Agent\Kontingent;
use App\Agent\Sprachmodell;
use App\Enums\Ability;
use App\Enums\AgentAction;
use App\Enums\AgentMode;
use App\Enums\GuardrailHit;
use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der Assistent: Not-Aus und Schwelle.
 *
 * **Der Not-Aus gehoert dorthin, wo ihn jemand unter Druck findet** -- nicht
 * in eine Konfigurationsdatei und nicht in ein Untermenue. Er wirkt sofort:
 * geprueft wird bei jedem Durchlauf, nicht beim Start eines Gespraechs
 * (Entscheidung G8).
 */
final class AgentController extends Controller
{
    public function __construct(
        private readonly Schutz $schutz,
        private readonly Kontingent $kontingent,
    ) {}

    public function edit(TenantContext $mandant, Sprachmodell $modell): Response
    {
        Gate::authorize(Ability::ManageAgent->value);

        $organisation = $mandant->current();

        return Inertia::render('settings/Agent', [
            'aktiv' => $this->schutz->mandantErlaubt(),
            'vorgabemodus' => $this->vorgabemodus($organisation),
            'schwelle' => $this->schutz->schwelle(),
            'boden' => (float) config('mrs.agent.confidence_floor'),
            'vorgabe' => (float) config('mrs.agent.confidence_threshold'),

            // Der Not-Aus der Installation steht ueber allem und ist hier
            // nicht abschaltbar -- aber sichtbar, sonst sucht jemand den
            // Fehler an der falschen Stelle.
            'killSwitch' => (bool) config('mrs.agent.kill_switch', false),
            'modellAngebunden' => $modell->angebunden(),
            'modell' => (string) config('mrs.agent.model'),

            // **Eine Zahl, ein Ort** (WP-06): das Kontingent kommt aus dem
            // Abo und wird in Laeufen gezeigt, wie die Praxis es dort sieht.
            // Aufgestockt wird unter Einstellungen -> Abo, nicht hier.
            'kontingent' => [
                'zeitraum' => $this->kontingent->zeitraum(),
                'verbraucht' => $this->kontingent->verbraucht(),
                'gesamt' => $this->kontingent->gesamt(),
                'rest' => $this->kontingent->rest(),
                'anteil' => round($this->kontingent->anteil(), 3),
                'knapp' => $this->kontingent->anteil() >= (float) config('mrs.agent.budget_warning_ratio'),
                'erschoepft' => $this->kontingent->erschoepft(),
            ],

            'letzteUebergaben' => $organisation instanceof Organization ? $this->uebergaben() : [],
        ]);
    }

    public function update(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageAgent->value);

        $daten = $request->validate([
            'aktiv' => ['required', 'boolean'],
            'schwelle' => ['required', 'numeric', 'between:0,1'],
            'vorgabemodus' => ['required', 'in:off,suggest,auto'],
        ]);

        $organisation = $mandant->current();

        if ($organisation instanceof Organization) {
            $einstellungen = $organisation->settings ?? [];

            data_set($einstellungen, 'agent.enabled', (bool) $daten['aktiv']);

            // **Nur fuer neue Gespraeche** (Entscheidung G2): laufende
            // behalten ihren Modus, sonst stellt eine Umstellung hundert
            // Gespraeche auf einmal um, die jemand einzeln entschieden hat.
            data_set($einstellungen, 'agent.default_mode', (string) $daten['vorgabemodus']);

            // **Nicht unter den Boden.** Wer die Schwelle auf null setzen
            // koennte, haette den Schutz abgeschafft, ohne ihn abzuschalten.
            data_set($einstellungen, 'agent.confidence_threshold', max(
                (float) config('mrs.agent.confidence_floor'),
                min(1.0, (float) $daten['schwelle']),
            ));

            $organisation->settings = $einstellungen;
            $organisation->save();
        }

        return back();
    }

    private function vorgabemodus(?Organization $organisation): string
    {
        $wert = $organisation instanceof Organization
            ? data_get($organisation->settings, 'agent.default_mode')
            : null;

        $modus = is_string($wert) ? AgentMode::tryFrom($wert) : null;

        return ($modus ?? AgentMode::Suggest)->value;
    }

    /**
     * Die letzten Uebergaben -- **im Produkt einsehbar**, nicht nur im Log.
     *
     * @return list<array<string, mixed>>
     */
    private function uebergaben(): array
    {
        /** @var list<array<string, mixed>> */
        return AgentRun::query()
            ->where('action', AgentAction::Escalated->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->with('conversation')
            ->get()
            ->map(fn (AgentRun $lauf): array => [
                'uuid' => $lauf->uuid,
                'gespraech' => $lauf->conversation?->uuid,
                'grund' => $lauf->escalation_reason,
                'regeln' => array_values(array_map(
                    fn (string $regel): string => (GuardrailHit::tryFrom($regel)?->label() ?? $regel),
                    $lauf->guardrails ?? [],
                )),
                'wann' => $lauf->created_at instanceof CarbonImmutable ? $lauf->created_at->toIso8601String() : null,
            ])
            ->values()
            ->all();
    }
}
