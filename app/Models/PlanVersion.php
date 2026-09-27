<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBinaryUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Eine Fassung des Pakets (WP-06b, Entscheidung B20).
 *
 * **Ein Preis bei Stripe ist unveraenderlich -- also ist es die Fassung
 * auch.** Speichern legt eine neue an; jedes Abo zeigt auf die Fassung seines
 * Abschlusses. Ein Trigger in der Datenbank haelt das fest, die Sperre hier
 * sagt es nur frueher und verstaendlicher.
 *
 * **Global, kein TenantModel:** das Paket gehoert dem Betreiber. Es gibt genau
 * ein Paket (B10) mit einer Reihe von Fassungen.
 *
 * @property int $number
 * @property string $name
 * @property int $base_cents
 * @property int $setup_cents
 * @property int $topup_cents
 * @property int $image_price_cents
 * @property int $included_messages
 * @property int $included_agent_runs
 * @property int $included_images
 * @property int $topup_messages
 * @property int $topup_agent_runs
 * @property int $trial_days
 * @property string|null $stripe_product_id
 * @property string|null $stripe_price_base
 * @property string|null $stripe_price_setup
 * @property string|null $stripe_price_topup
 * @property string|null $stripe_price_image
 * @property string $stripe_state pending | ready | failed
 * @property string|null $stripe_error
 * @property bool $migrate_existing
 * @property string $reason
 * @property string|null $created_by_user_id
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $created_at
 */
class PlanVersion extends Model
{
    use HasBinaryUuid;

    public const BEREIT = 'ready';

    public const AUSSTEHEND = 'pending';

    public const GESCHEITERT = 'failed';

    /** Was sich an einer Fassung aendern darf, solange sie nicht gilt. */
    private const STRIPEFELDER = [
        'stripe_product_id', 'stripe_price_base', 'stripe_price_setup', 'stripe_price_topup',
        'stripe_price_image', 'stripe_state', 'stripe_error', 'activated_at', 'updated_at',
    ];

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['created_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'base_cents' => 'integer',
            'setup_cents' => 'integer',
            'topup_cents' => 'integer',
            'image_price_cents' => 'integer',
            'included_messages' => 'integer',
            'included_agent_runs' => 'integer',
            'included_images' => 'integer',
            'topup_messages' => 'integer',
            'topup_agent_runs' => 'integer',
            'trial_days' => 'integer',
            'migrate_existing' => 'boolean',
            'activated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PlanVersion $fassung): void {
            $geaendert = array_keys($fassung->getDirty());

            if ($fassung->getOriginal('activated_at') !== null || array_diff($geaendert, self::STRIPEFELDER) !== []) {
                throw new RuntimeException('Eine Paketfassung ist unveränderlich. Legen Sie eine neue an (WP-06b).');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Eine Paketfassung wird nicht gelöscht (WP-06b).');
        });
    }

    public function gilt(): bool
    {
        return $this->activated_at instanceof CarbonImmutable;
    }

    /**
     * Hat Stripe alle Preise dieser Fassung? Im Testbetrieb nie. Eine
     * Einrichtung, die nichts kostet, braucht keinen.
     */
    public function hatStripePreise(): bool
    {
        return $this->stripe_price_base !== null && $this->stripe_price_topup !== null
            && $this->stripe_price_image !== null && ($this->stripe_price_setup !== null || $this->setup_cents === 0);
    }
}
