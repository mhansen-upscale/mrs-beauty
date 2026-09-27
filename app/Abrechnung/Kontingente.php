<?php

declare(strict_types=1);

namespace App\Abrechnung;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Was im Abo enthalten ist -- und was davon noch da ist.
 *
 * **Begrenzt wird, was Geld kostet** (Entscheidung B12). Eine Antwort im
 * offenen Service-Fenster kostet nichts und wird **nie** gesperrt; ein
 * Template ausserhalb kostet und zaehlt.
 *
 * Eine Praxis darf nie daran gehindert werden, einer Patientin zu antworten.
 * Das ist keine Kulanz, sondern der Unterschied zwischen einem Werkzeug und
 * einer Falle.
 */
final class Kontingente
{
    public function __construct(
        private readonly Nutzungsuebersicht $nutzung,
        private readonly TenantContext $mandant,
        private readonly Paket $paket,
    ) {}

    /** Das Abo dieser Praxis -- oder ein frisches in der Testphase. */
    public function abo(): Subscription
    {
        $abo = Subscription::query()->first();

        if ($abo instanceof Subscription) {
            return $abo;
        }

        // **Ab dem Anlegen der Praxis** (B18), nicht ab jetzt: die Zeile
        // entsteht beim ersten Zugriff -- beim Agenten, beim Monatslauf, auf
        // der Abo-Seite --, und die Testphase begaenne sonst irgendwann.
        $angelegt = $this->mandant->current()?->getAttribute('created_at');

        // Ein neues Abo gilt unter der aktuellen Fassung (WP-06b).
        $fassung = $this->paket->aktuell();

        $neu = new Subscription;
        $neu->status = SubscriptionStatus::Trialing;
        $neu->plan_version_id = $fassung->getKey();
        $neu->trial_ends_at = ($angelegt instanceof CarbonImmutable ? $angelegt : CarbonImmutable::now())
            ->addDays($fassung->trial_days);
        $neu->save();

        return $neu;
    }

    /**
     * @return array<string, int>
     */
    public function enthalten(): array
    {
        return $this->enthaltenIn($this->abo());
    }

    /**
     * @return array<string, int>
     */
    public function rest(?CarbonImmutable $jetzt = null): array
    {
        return $this->restVon($this->enthalten(), $jetzt);
    }

    /**
     * Enthalten und Rest -- **nur lesend**.
     *
     * `abo()` legt ein Abo an, wenn keines da ist. Fuer eine Anzeige waere
     * das ein Schreibvorgang bei jedem Seitenaufruf (dieselbe Abwaegung wie in
     * EnsureAboGilt). Ohne Abo gilt, was jede Praxis in der Testphase hat.
     *
     * @return array{enthalten: array<string, int>, rest: array<string, int>}
     */
    public function stand(?CarbonImmutable $jetzt = null): array
    {
        $enthalten = $this->enthaltenIn(Subscription::query()->first());

        return ['enthalten' => $enthalten, 'rest' => $this->restVon($enthalten, $jetzt)];
    }

    /**
     * @return array<string, int>
     */
    private function enthaltenIn(?Subscription $abo): array
    {
        // **Die Fassung des Abos, nicht die aktuelle** (WP-06b, B20): wer im
        // Bestandsschutz ist, behaelt sein Kontingent.
        $fassung = $this->paket->fuer($abo);

        return [
            'nachrichten' => $fassung->included_messages + ($abo->extra_messages ?? 0),
            'agentenlaeufe' => $fassung->included_agent_runs + ($abo->extra_agent_runs ?? 0),

            // Ein eigener Zaehler: ein Bild kostet ein Vielfaches eines
            // Textlaufs (WP-31).
            'bilder' => $fassung->included_images + ($abo->extra_images ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $enthalten
     * @return array<string, int>
     */
    private function restVon(array $enthalten, ?CarbonImmutable $jetzt): array
    {
        $jetzt ??= CarbonImmutable::now();
        $von = $jetzt->startOfMonth();
        $bis = $jetzt->endOfMonth();

        return [
            'nachrichten' => max(0, $enthalten['nachrichten'] - $this->nutzung->kostenpflichtige($von, $bis)),
            'agentenlaeufe' => max(0, $enthalten['agentenlaeufe'] - $this->nutzung->agentenlaeufe($von, $bis)),
            'bilder' => max(0, $enthalten['bilder'] - $this->nutzung->bilder($von, $bis)),
        ];
    }

    /**
     * Darf eine **kostenpflichtige** Nachricht hinaus?
     *
     * Der Aufrufer entscheidet, ob seine Nachricht eine ist -- im offenen
     * Fenster fragt er gar nicht erst.
     */
    public function darfKostenpflichtigSenden(?CarbonImmutable $jetzt = null): bool
    {
        // Die eine Stelle fuer jede Abo-Sperre (WP-34c): unbezahlt,
        // pausiert, Testphase abgelaufen, gekuendigt.
        if (! $this->abo()->zugang($jetzt)->darfNutzen()) {
            return false;
        }

        return $this->rest($jetzt)['nachrichten'] > 0;
    }

    /** Stockt auf -- bezahlt wird ueber Stripe, gebucht wird hier. */
    public function stockeAuf(string $art, int $menge): void
    {
        $abo = $this->abo();

        match ($art) {
            'nachrichten' => $abo->extra_messages += $menge,
            'agentenlaeufe' => $abo->extra_agent_runs += $menge,
            'bilder' => $abo->extra_images += $menge,
            default => null,
        };

        $abo->save();
    }

    /**
     * Zu Beginn einer neuen Periode faellt Aufgestocktes weg -- alles davon --,
     * und eine wartende Paketfassung gilt.
     *
     * **Auch die Bilder.** `extra_images` ist die Summe der Kaeufe, kein
     * Restguthaben: `rest()` zieht nur den Verbrauch des laufenden Monats ab.
     * Bis zum 27.09.2026 fehlte die Zeile hier, und wer einmal zehn Bilder
     * kaufte, hatte fortan jeden Monat vierzig statt dreissig (B13).
     */
    public function neuePeriode(CarbonImmutable $beginn, CarbonImmutable $ende): void
    {
        $abo = $this->abo();

        // Eine Umstellung auf eine neue Fassung wirkt ab hier -- nicht mitten
        // im Monat (WP-06b AK 12): wer am 15. von 600 auf 400 Laeufe faellt,
        // haette rueckwirkend keinen Assistenten mehr.
        $wartend = $abo->pending_plan_version_id;

        if (is_string($wartend)) {
            $fassung = PlanVersion::query()->whereKey($wartend)->first();

            if ($fassung instanceof PlanVersion) {
                $this->paket->wechsle($abo, $fassung);
            }
        }

        $abo->period_starts_at = $beginn;
        $abo->period_ends_at = $ende;
        $abo->extra_messages = 0;
        $abo->extra_agent_runs = 0;
        $abo->extra_images = 0;
        $abo->save();
    }

    public function praxis(): ?Organization
    {
        return $this->mandant->current();
    }
}
