<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Was gebucht wird -- "Erstberatung Botox", 30 Minuten.
 *
 * Nicht dasselbe wie eine Behandlung: die traegt Preis und Umsatzschaetzung,
 * die Terminart traegt Dauer, Ruestzeit und Vorlauf.
 *
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $treatment_id
 * @property int $duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int $lead_time_hours
 * @property string $color
 * @property bool $is_public
 * @property bool $is_active
 */
class AppointmentType extends TenantModel
{
    use Auditable;

    /** @use HasFactory<AppointmentTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'treatment_id',
        'duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'lead_time_hours',
        'color',
        'is_public',
        'is_active',
    ];

    /** @var list<string> */
    protected $hidden = ['treatment_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'lead_time_hours' => 'integer',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'lead_time_hours', 'is_public', 'is_active'];
    }

    /**
     * Was im Kalender belegt wird (Bedingung V11).
     *
     * Ruestzeit davor + Dauer + Ruestzeit danach.
     */
    public function belegteDauer(): int
    {
        return $this->buffer_before_minutes + $this->duration_minutes + $this->buffer_after_minutes;
    }

    /**
     * Was dem Kontakt als Terminzeit angezeigt wird.
     *
     * **Nur die Dauer.** Wer hier die Ruestzeit mitrechnet, verschiebt jede
     * Terminanzeige -- der Kontakt liest dann 13:45 und kommt zu frueh.
     */
    public function angezeigteDauer(): int
    {
        return $this->duration_minutes;
    }

    /**
     * Wird diese Terminart von diesem Behandler an diesem Standort angeboten?
     *
     * Fasst V7 und V8 zusammen, wie Practitioner::arbeitetAm() es fuer V1 bis
     * V3 tut. WP-10 setzt auf beiden auf.
     */
    public function wirdAngebotenVon(Practitioner $behandler, Location $standort): bool
    {
        if (! $this->is_active || ! $behandler->is_active || ! $standort->is_active) {
            return false;
        }

        // V7 -- Behandlerfreigabe
        if (! $this->practitioners()->whereKey($behandler->getKey())->exists()) {
            return false;
        }

        // V8 -- Angebot am Standort
        return $this->locations()->whereKey($standort->getKey())->exists();
    }

    /**
     * Liegt dieser Zeitpunkt jenseits der Vorlaufzeit? (Bedingung V9)
     *
     * Ein Termin in zwei Stunden ist fuer eine Beratung denkbar und fuer eine
     * Operation nicht.
     */
    public function istBuchbarAm(CarbonInterface $zeitpunkt, ?CarbonInterface $jetzt = null): bool
    {
        $jetzt ??= now();

        return $zeitpunkt->getTimestamp() - $jetzt->getTimestamp() >= $this->lead_time_hours * 3600;
    }

    /** Der geschaetzte Umsatz dieser Terminart. 0 ohne Behandlungsbezug. */
    public function umsatzwertCents(): int
    {
        $behandlung = $this->treatment;

        return $behandlung instanceof Treatment ? $behandlung->avg_revenue_cents : 0;
    }

    /**
     * @param  Builder<AppointmentType>  $query
     * @return Builder<AppointmentType>
     */
    public function scopeAktiv(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    /**
     * @return BelongsToMany<Practitioner, $this, AppointmentTypePractitioner>
     */
    public function practitioners(): BelongsToMany
    {
        return $this->belongsToMany(Practitioner::class, 'appointment_type_practitioner')
            ->using(AppointmentTypePractitioner::class)
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Location, $this, AppointmentTypeLocation>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'appointment_type_location')
            ->using(AppointmentTypeLocation::class)
            ->withTimestamps();
    }
}
