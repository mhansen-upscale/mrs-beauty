<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AbsenceReason;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Abwesenheit des Behandlers (Bedingung V2 der Verfuegbarkeit).
 *
 * Absoluter Zeitraum, deshalb UTC.
 *
 * @property string $practitioner_id
 * @property AbsenceReason $reason
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class Absence extends TenantModel
{
    use Auditable;

    protected $fillable = ['practitioner_id', 'reason', 'note', 'starts_at', 'ends_at'];

    /** @var list<string> */
    protected $hidden = ['practitioner_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => AbsenceReason::class,
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
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }
}
