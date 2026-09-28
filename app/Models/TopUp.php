<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Eine bezahlte Aufstockung (WP-34d).
 *
 * **Mit Zeitpunkt und Betrag.** `extra_*` am Abo sagt, wie viel noch da
 * ist, nicht wann und fuer wie viel es gekauft wurde.
 *
 * @property string $article
 * @property int $quantity
 * @property int $amount_cents
 * @property CarbonImmutable $paid_at
 * @property string $stripe_checkout_id
 */
class TopUp extends TenantModel
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount_cents' => 'integer',
            'paid_at' => 'immutable_datetime',
        ];
    }
}
