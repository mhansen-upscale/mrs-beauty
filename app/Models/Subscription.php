<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;

/**
 * Das Abo einer Praxis.
 *
 * **Protokolliert** (WP-05): ein Zustandswechsel entscheidet darueber, was
 * eine Praxis darf, und muss sich nachlesen lassen.
 *
 * @property SubscriptionStatus $status
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property CarbonImmutable|null $period_starts_at
 * @property CarbonImmutable|null $period_ends_at
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $canceled_at
 * @property int $extra_messages
 * @property int $extra_agent_runs
 * @property int $extra_images
 * @property string|null $service_window_billed_period Letzter abgerechneter Monat, `Y-m` (B14)
 */
class Subscription extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['stripe_customer_id', 'stripe_subscription_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'period_starts_at' => 'immutable_datetime',
            'period_ends_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'extra_messages' => 'integer',
            'extra_agent_runs' => 'integer',
            'extra_images' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['status', 'extra_messages', 'extra_agent_runs', 'extra_images'];
    }

    /**
     * Laeuft die Testphase noch?
     *
     * Ohne Abo ist eine Praxis in der Probezeit und **nicht gesperrt**: wer
     * eine Praxis am ersten Tag aussperrt, bekommt keinen zweiten.
     */
    public function inTestphase(?CarbonImmutable $jetzt = null): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && ($this->trial_ends_at === null || $this->trial_ends_at->greaterThan($jetzt ?? CarbonImmutable::now()));
    }
}
