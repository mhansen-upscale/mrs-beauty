<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HoldPurpose;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Reservierung auf einer Slot-Strecke.
 *
 * **Ein abgelaufener Hold ist sofort abgelaufen.** Jede Abfrage vergleicht
 * gegen die Uhr -- ein Aufraeumjob gibt nur Zeilen frei, er entscheidet
 * nichts. Wer sich auf den Job verlaesst, haelt Slots laenger blockiert, als
 * er gesagt hat.
 *
 * @property HoldPurpose $purpose
 * @property CarbonImmutable $blocked_from
 * @property CarbonImmutable $blocked_until
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $released_at
 * @property string $practitioner_id
 * @property string $location_id
 * @property string $appointment_type_id
 */
class SlotHold extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'appointment_type_id',
        'practitioner_id',
        'location_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => HoldPurpose::class,
            'blocked_from' => 'immutable_datetime',
            'blocked_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['purpose'];
    }

    public function giltNoch(): bool
    {
        return $this->released_at === null && $this->expires_at->isFuture();
    }

    public function istAbgelaufen(): bool
    {
        return $this->released_at === null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<SlotHold>  $query
     * @return Builder<SlotHold>
     */
    public function scopeGueltig(Builder $query): Builder
    {
        return $query->whereNull('released_at')->where('expires_at', '>', now());
    }

    /**
     * @return HasMany<AppointmentSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(AppointmentSlot::class, 'slot_hold_id');
    }

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }
}
