<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClosureReason;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Schliesszeit des Standorts (Bedingung V3 der Verfuegbarkeit).
 *
 * Absoluter Zeitraum, deshalb UTC -- anders als die wiederkehrende
 * Arbeitszeit, die eine Regel in Ortszeit ist.
 *
 * @property string $location_id
 * @property ClosureReason $reason
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class LocationClosure extends TenantModel
{
    use Auditable;

    protected $fillable = ['location_id', 'reason', 'note', 'starts_at', 'ends_at'];

    /** @var list<string> */
    protected $hidden = ['location_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ClosureReason::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['reason'];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
