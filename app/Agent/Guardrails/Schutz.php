<?php

declare(strict_types=1);

namespace App\Agent\Guardrails;

use App\Enums\AgentAction;
use App\Enums\AgentMode;
use App\Enums\GuardrailHit;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Not-Aus, Konfidenz und Gespraechslaenge.
 *
 * Drei Ebenen des Not-Aus, **alle sofort wirksam** (Entscheidung G8):
 *
 * 1. **Installation** -- `AGENT_KILL_SWITCH`. Ueberstimmt alles.
 * 2. **Mandant** -- ein Schalter in den Einstellungen.
 * 3. **Konversation** -- Modus `off` oder eine Pause bis zu einem Zeitpunkt.
 *
 * Sofort wirksam heisst: geprueft wird bei **jedem** Durchlauf, nicht beim
 * Start eines Gespraechs. Ein Schalter, der erst beim naechsten Dialog
 * greift, ist im Ernstfall keiner.
 */
final class Schutz
{
    public function __construct(private readonly TenantContext $mandant) {}

    /**
     * Darf der Agent in diesem Gespraech ueberhaupt arbeiten?
     */
    public function notAus(Conversation $gespraech, ?CarbonImmutable $jetzt = null): ?GuardrailHit
    {
        if ((bool) config('mrs.agent.kill_switch', false)) {
            return GuardrailHit::KillSwitch;
        }

        if (! $this->mandantErlaubt()) {
            return GuardrailHit::TenantDisabled;
        }

        if ($gespraech->agent_mode === AgentMode::Off) {
            return null;
        }

        $jetzt ??= CarbonImmutable::now();

        return $gespraech->agent_paused_until instanceof CarbonImmutable
            && $gespraech->agent_paused_until->greaterThan($jetzt)
                ? GuardrailHit::Paused
                : null;
    }

    /** Der Schalter des Mandanten. Vorgabe: der Agent darf. */
    public function mandantErlaubt(): bool
    {
        $organisation = $this->mandant->current();

        if (! $organisation instanceof Organization) {
            return false;
        }

        $wert = data_get($organisation->settings, 'agent.enabled');

        return $wert === null || (bool) $wert;
    }

    /**
     * Die Konfidenzschwelle dieses Mandanten.
     *
     * Je Mandant senkbar -- **aber nicht unter den Boden** (Schritt 5). Wer
     * die Schwelle auf null setzen koennte, haette den Schutz abgeschafft,
     * ohne ihn abzuschalten.
     */
    public function schwelle(): float
    {
        $vorgabe = (float) config('mrs.agent.confidence_threshold', 0.7);
        $boden = (float) config('mrs.agent.confidence_floor', 0.5);

        $organisation = $this->mandant->current();
        $eigene = $organisation instanceof Organization
            ? data_get($organisation->settings, 'agent.confidence_threshold')
            : null;

        if (! is_numeric($eigene)) {
            return $vorgabe;
        }

        return max($boden, min(1.0, (float) $eigene));
    }

    /** Schritt 5: unter der Schwelle wird eskaliert. */
    public function zuUnsicher(float $sicherheit): bool
    {
        return $sicherheit < $this->schwelle();
    }

    /**
     * Schritt 5: nach fuenf automatischen Antworten hintereinander ohne
     * menschliche Beteiligung wird eskaliert.
     *
     * **Ein Gespraech, das so lange nicht zum Abschluss kommt, laeuft
     * falsch.** Gezaehlt werden die juengsten Laeufe, die selbst geantwortet
     * haben; ein Vorschlag, den ein Mensch abgeschickt hat, ist menschliche
     * Beteiligung und setzt die Zaehlung zurueck.
     */
    public function zuVieleAutomatischeAntworten(Conversation $gespraech): bool
    {
        $grenze = (int) config('mrs.agent.max_consecutive_auto_replies', 5);

        $letzte = AgentRun::query()
            ->where('conversation_id', $gespraech->getKey())
            ->whereIn('action', [AgentAction::Answered->value, AgentAction::Suggested->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($grenze)
            ->get();

        if ($letzte->count() < $grenze) {
            return false;
        }

        return $letzte->every(fn (AgentRun $lauf): bool => $lauf->action === AgentAction::Answered);
    }

    /**
     * Haelt den Agenten aus dem Gespraech heraus, bis ein Mensch ihn wieder
     * hereinlaesst.
     *
     * Nach einer Komplikation, einer Beschwerde oder einem Bild soll er nicht
     * bei der naechsten Nachricht weitermachen, als waere nichts gewesen. Die
     * Dauer steht in der Konfiguration; aufheben kann sie jeder, der den
     * Modus umstellen darf.
     */
    public function pausiere(Conversation $gespraech, ?CarbonImmutable $jetzt = null): void
    {
        $stunden = (int) config('mrs.agent.escalation_pause_hours', 24);

        $gespraech->agent_paused_until = ($jetzt ?? CarbonImmutable::now())->addHours($stunden);
        $gespraech->save();
    }
}
