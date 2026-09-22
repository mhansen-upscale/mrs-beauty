<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancellationReason;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Termin.
 *
 * `starts_at` ist die **angezeigte** Zeit, `blocked_from` die belegte. Der
 * Unterschied ist die Ruestzeit (WP-09). Wer beides vermischt, zeigt dem
 * Kontakt eine Uhrzeit, zu der er nicht drankommt.
 *
 * Ein Termin belegt seine Zeit ueber `appointment_slots`, nicht ueber seine
 * eigenen Zeitspalten. Die Spalten sind die Anzeige, die Slots sind die
 * Wahrheit -- Entscheidung A9, weil MySQL keine Exclusion Constraints kennt.
 *
 * **Bewusst ohne Notizfeld.** Ein Freitext am Termin fuellt sich innerhalb
 * von Wochen mit Behandlungsverlaeufen; Entscheidung P1 schliesst
 * Behandlungsdokumentation aus, weil sonst § 630f BGB greift. Notizen mit
 * Zweckbindung und Aufbewahrungsfrist gehoeren zu WP-18.
 *
 * @property string $appointment_type_id
 * @property string $practitioner_id
 * @property string $location_id
 * @property string $contact_id
 * @property AppointmentStatus $status
 * @property BookingChannel $booked_via
 * @property bool $is_override
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $blocked_from
 * @property CarbonImmutable $blocked_until
 * @property CarbonImmutable|null $consent_accepted_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CancellationReason|null $cancellation_reason
 * @property-read AppointmentType $appointmentType
 * @property-read Practitioner $practitioner
 * @property-read Location $location
 * @property-read Contact $contact
 */
class Appointment extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'appointment_type_id',
        'practitioner_id',
        'location_id',
        'contact_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'booked_via' => BookingChannel::class,
            'cancellation_reason' => CancellationReason::class,
            'is_override' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'blocked_from' => 'immutable_datetime',
            'blocked_until' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'consent_accepted_at' => 'immutable_datetime',
        ];
    }

    /**
     * Statuswechsel, Buchungskanal und Uebersteuerung gehoeren mit Wert ins
     * Protokoll -- keiner der drei sagt etwas ueber eine Person aus
     * (Entscheidung C5). Wer der Kontakt ist, bleibt draussen.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['status', 'booked_via', 'is_override', 'cancellation_reason'];
    }

    /** Laesst sich dieser Termin noch verschieben oder umbuchen? */
    public function istAenderbar(): bool
    {
        return in_array($this->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true);
    }

    /**
     * Termine, die ihre Zeit noch belegen.
     *
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeAktiv(Builder $query): Builder
    {
        return $query->where('status', '!=', AppointmentStatus::Cancelled->value);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<AppointmentSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(AppointmentSlot::class);
    }

    /**
     * @return HasMany<AppointmentNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(AppointmentNotification::class);
    }

    /**
     * @return BelongsTo<AppointmentType, $this>
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
