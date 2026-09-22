<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WaitlistOfferStatus;
use App\Enums\WaitlistTrigger;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Angebot an einen Wartelisteneintrag.
 *
 * **Je Eintrag hoechstens eines offen** (K9) -- durchgesetzt von einem
 * Unique-Index auf einer generierten Spalte, nicht von einer Pruefung im
 * Code: zwei gleichzeitige Vergabelaeufe bestuenden eine Pruefung beide.
 *
 * `cost_micros` stammt aus der Antwort des Anbieters, nie aus einer
 * Schaetzung (Entscheidungen B7, B8).
 *
 * @property WaitlistOfferStatus $status
 * @property WaitlistTrigger $trigger
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $blocked_from
 * @property CarbonImmutable $blocked_until
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $answered_at
 * @property int|null $cost_micros
 * @property CarbonImmutable|null $created_at
 * @property string $waitlist_entry_id
 * @property string|null $slot_hold_id
 * @property string|null $appointment_id
 * @property string $practitioner_id
 * @property string $location_id
 * @property string $appointment_type_id
 * @property string $entry_key
 * @property-read WaitlistEntry $entry
 */
class WaitlistOffer extends TenantModel
{
    use Auditable;

    protected $table = 'waitlist_offers';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'waitlist_entry_id',
        'slot_hold_id',
        'appointment_id',
        'practitioner_id',
        'location_id',
        'appointment_type_id',
        'entry_key',
        'offer_guard',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WaitlistOfferStatus::class,
            'trigger' => WaitlistTrigger::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'blocked_from' => 'immutable_datetime',
            'blocked_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
            'cost_micros' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['status', 'trigger'];
    }

    /**
     * @param  Builder<WaitlistOffer>  $query
     * @return Builder<WaitlistOffer>
     */
    public function scopeOffen(Builder $query): Builder
    {
        return $query->where('status', WaitlistOfferStatus::Pending->value);
    }

    public function giltNoch(?CarbonImmutable $jetzt = null): bool
    {
        return $this->status === WaitlistOfferStatus::Pending
            && $this->expires_at->greaterThan($jetzt ?? CarbonImmutable::now());
    }

    /**
     * @return BelongsTo<WaitlistEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'waitlist_entry_id');
    }

    /**
     * @return BelongsTo<SlotHold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(SlotHold::class, 'slot_hold_id');
    }
}
