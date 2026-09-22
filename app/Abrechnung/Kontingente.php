<?php

declare(strict_types=1);

namespace App\Abrechnung;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
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
    ) {}

    /** Das Abo dieser Praxis -- oder ein frisches in der Testphase. */
    public function abo(): Subscription
    {
        $abo = Subscription::query()->first();

        if ($abo instanceof Subscription) {
            return $abo;
        }

        $neu = new Subscription;
        $neu->status = SubscriptionStatus::Trialing;
        $neu->trial_ends_at = CarbonImmutable::now()->addDays((int) config('mrs.billing.trial_days', 30));
        $neu->save();

        return $neu;
    }

    /**
     * @return array<string, int>
     */
    public function enthalten(): array
    {
        $abo = $this->abo();

        return [
            'nachrichten' => (int) config('mrs.billing.included.messages') + $abo->extra_messages,
            'agentenlaeufe' => (int) config('mrs.billing.included.agent_runs') + $abo->extra_agent_runs,

            // Ein eigener Zaehler: ein Bild kostet ein Vielfaches eines
            // Textlaufs (WP-31).
            'bilder' => (int) config('mrs.billing.included.images') + $abo->extra_images,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function rest(?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();
        $von = $jetzt->startOfMonth();
        $bis = $jetzt->endOfMonth();
        $enthalten = $this->enthalten();

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
        if (! $this->abo()->status->darfNutzen()) {
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

    /** Zu Beginn einer neuen Periode faellt Aufgestocktes weg. */
    public function neuePeriode(CarbonImmutable $beginn, CarbonImmutable $ende): void
    {
        $abo = $this->abo();

        $abo->period_starts_at = $beginn;
        $abo->period_ends_at = $ende;
        $abo->extra_messages = 0;
        $abo->extra_agent_runs = 0;
        $abo->save();
    }

    public function praxis(): ?Organization
    {
        return $this->mandant->current();
    }
}
