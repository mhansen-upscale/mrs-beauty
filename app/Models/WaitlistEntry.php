<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WaitlistStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wer auf einen frueheren Termin wartet.
 *
 * **`min_notice_hours` ist das Feld, an dem die Warteliste steht und faellt**
 * (Bedingung K8). Manche koennen in zwei Stunden da sein, andere brauchen
 * zwei Tage Vorlauf. Ohne dieses Feld verschickt das System ueberwiegend
 * Angebote, die niemand annehmen kann -- und jeder Fehlversuch kostet bei
 * WhatsApp echtes Geld.
 *
 * @property WaitlistStatus $status
 * @property bool $all_locations
 * @property CarbonImmutable $earliest_date
 * @property CarbonImmutable $latest_date
 * @property int $weekday_mask
 * @property array<int, array<string, string>>|null $time_windows
 * @property int $min_notice_hours
 * @property int $priority
 * @property CarbonImmutable $expires_at
 * @property int $offers_sent_count
 * @property CarbonImmutable|null $last_offered_at
 * @property string $contact_id
 * @property string $appointment_type_id
 * @property string|null $practitioner_id
 * @property CarbonImmutable|null $created_at
 * @property-read Contact $contact
 */
class WaitlistEntry extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['contact_id', 'appointment_type_id', 'practitioner_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WaitlistStatus::class,
            'all_locations' => 'boolean',
            'earliest_date' => 'immutable_date',
            'latest_date' => 'immutable_date',
            'weekday_mask' => 'integer',
            'time_windows' => 'array',
            'min_notice_hours' => 'integer',
            'priority' => 'integer',
            'expires_at' => 'immutable_datetime',
            'offers_sent_count' => 'integer',
            'last_offered_at' => 'immutable_datetime',
        ];
    }

    /**
     * Zustand und Rang duerfen mit Wert ins Protokoll: keiner sagt etwas
     * ueber eine Person (Entscheidung C5). Die Prioritaet ist von Hand
     * erhoehbar, und dann soll nachlesbar sein, von wessen Hand.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['status', 'priority'];
    }

    /**
     * @param  Builder<WaitlistEntry>  $query
     * @return Builder<WaitlistEntry>
     */
    public function scopeWartend(Builder $query): Builder
    {
        return $query->where('status', WaitlistStatus::Active->value);
    }

    /** K6: passt der lokale Wochentag? Montag ist Bit 0. */
    public function passtWochentag(int $isoWochentag): bool
    {
        return ($this->weekday_mask & (1 << ($isoWochentag - 1))) !== 0;
    }

    /**
     * K7: faellt die lokale Startzeit in eines der Fenster?
     *
     * Ohne Fenster passt jede Zeit -- wer nichts einschraenkt, meint nicht
     * "nie".
     */
    public function passtUhrzeit(string $ortszeit): bool
    {
        $fenster = $this->time_windows ?? [];

        if ($fenster === []) {
            return true;
        }

        foreach ($fenster as $spanne) {
            $von = (string) ($spanne['von'] ?? '00:00');
            $bis = (string) ($spanne['bis'] ?? '23:59');

            if ($ortszeit >= $von && $ortszeit <= $bis) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<AppointmentType, $this>
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    /**
     * @return BelongsToMany<Location, $this, WaitlistEntryLocation>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'waitlist_entry_location')
            ->using(WaitlistEntryLocation::class)
            ->withTimestamps();
    }

    /**
     * @return HasMany<WaitlistOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(WaitlistOffer::class);
    }
}
