<?php

declare(strict_types=1);

namespace App\Models;

use App\Abrechnung\Paket;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property CarbonImmutable|null $paused_at Der Einzug ruht (WP-34c); Stripes Status bleibt dabei `active`
 * @property CarbonImmutable|null $pause_resumes_at
 * @property bool $cancel_at_period_end
 * @property CarbonImmutable|null $cancel_at
 * @property CarbonImmutable|null $discount_ends_at
 * @property CarbonImmutable|null $activated_at Der erste Wechsel auf `active` (WP-34d)
 * @property CarbonImmutable|null $stripe_event_at Das juengste angewandte Ereignis
 * @property string|null $plan_version_id Die Paketfassung des Abschlusses (WP-06b, B20), binaer
 * @property string|null $pending_plan_version_id Wartet auf die naechste Periode
 * @property-read PlanVersion|null $planVersion
 */
class Subscription extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['stripe_customer_id', 'stripe_subscription_id', 'plan_version_id', 'pending_plan_version_id'];

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
            'paused_at' => 'immutable_datetime',
            'pause_resumes_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
            'cancel_at' => 'immutable_datetime',
            'discount_ends_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'stripe_event_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        // Alles, was Stripe meldet oder der Betreiber veranlasst -- ein
        // Zustandswechsel muss sich nachlesen lassen (WP-34c).
        return [
            'status', 'extra_messages', 'extra_agent_runs', 'extra_images',
            'trial_ends_at', 'paused_at', 'pause_resumes_at', 'cancel_at_period_end',
            'cancel_at', 'discount_ends_at', 'activated_at',
        ];
    }

    /**
     * Laeuft die Testphase noch?
     *
     * Ohne Abo ist eine Praxis in der Probezeit und **nicht gesperrt**: wer
     * eine Praxis am ersten Tag aussperrt, bekommt keinen zweiten.
     */
    public function inTestphase(?CarbonImmutable $jetzt = null): bool
    {
        return $this->zugang($jetzt) === SubscriptionAccess::Trial;
    }

    /**
     * Was das Abo der Praxis gerade erlaubt (WP-34c) -- **die eine Stelle**.
     *
     * - Eine **Pause** sperrt, gleich was Stripe als Status fuehrt: ein
     *   pausierter Einzug laesst das Abo bei Stripe `active` (B17).
     * - Die **Testphase** endet an `trial_ends_at` -- und ohne Datum dort, wo
     *   sie regulaer endet: `trial_days` nach dem Anlegen der Praxis (B18).
     *   Eine Testphase bei Stripe (mit Abo-Kennung) entscheidet Stripe.
     * - **Zahlung offen** sperrt nicht. Stripe mahnt mehrfach (WP-06).
     */
    public function zugang(?CarbonImmutable $jetzt = null): SubscriptionAccess
    {
        $jetzt ??= CarbonImmutable::now();

        if ($this->paused_at instanceof CarbonImmutable) {
            return SubscriptionAccess::Paused;
        }

        return match ($this->status) {
            SubscriptionStatus::Active, SubscriptionStatus::PastDue => SubscriptionAccess::Open,
            SubscriptionStatus::Unpaid => SubscriptionAccess::Unpaid,
            SubscriptionStatus::Canceled => SubscriptionAccess::Canceled,
            SubscriptionStatus::Paused => SubscriptionAccess::Paused,
            SubscriptionStatus::Trialing => is_string($this->stripe_subscription_id) && $this->stripe_subscription_id !== ''
                ? SubscriptionAccess::Trial
                : ($this->testphasenende()->greaterThan($jetzt) ? SubscriptionAccess::Trial : SubscriptionAccess::TrialExpired),
        };
    }

    /** Wann die Testphase endet -- oder regulaer enden wuerde (B18). */
    public function testphasenende(): CarbonImmutable
    {
        if ($this->trial_ends_at instanceof CarbonImmutable) {
            return $this->trial_ends_at;
        }

        $angelegt = Organization::query()
            ->whereKey($this->getAttributes()['organization_id'] ?? null)
            ->first(['id', 'created_at'])
            ?->getAttribute('created_at');

        return ($angelegt instanceof CarbonInterface ? CarbonImmutable::instance($angelegt) : CarbonImmutable::now())
            ->addDays(app(Paket::class)->fuer($this)->trial_days);
    }

    /**
     * Die Fassung des Abschlusses -- gelesen ueber `Paket::fuer()`, das ohne
     * Zuordnung auf die aktuelle faellt. Die Beziehung dient dem Vorladen.
     *
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }
}
