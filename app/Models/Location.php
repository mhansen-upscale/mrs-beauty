<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\LocationFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Standort der Praxis.
 *
 * Eine Praxisgruppe ist **ein** Mandant mit mehreren Standorten
 * (Entscheidung D11) -- nicht mehrere Organisationen. Sonst entstehen
 * doppelte Kontakte und eine falsche Auswertung.
 *
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string|null $street
 * @property string|null $postal_code
 * @property string|null $city
 * @property string $country
 * @property string|null $meta_city_key
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_active
 */
class Location extends TenantModel
{
    use Auditable;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'street',
        'postal_code',
        'city',
        'country',
        'phone',
        'email',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['timezone', 'is_active'];
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /**
     * Ein Zeitpunkt in der Ortszeit **dieses** Standorts.
     *
     * Der einzige richtige Weg, aus einem gespeicherten UTC-Zeitpunkt eine
     * fachliche Aussage zu machen (Entscheidung A8).
     */
    public function ortszeit(CarbonInterface $zeitpunkt): CarbonImmutable
    {
        return CarbonImmutable::instance($zeitpunkt)->setTimezone($this->zone());
    }

    /** Ist der Standort zu diesem Zeitpunkt geschlossen? (Bedingung V3) */
    public function istGeschlossenAm(CarbonInterface $zeitpunkt): bool
    {
        return $this->closures()
            ->where('starts_at', '<=', $zeitpunkt)
            ->where('ends_at', '>', $zeitpunkt)
            ->exists();
    }

    /**
     * @return BelongsToMany<Practitioner, $this, PractitionerLocation>
     */
    public function practitioners(): BelongsToMany
    {
        return $this->belongsToMany(Practitioner::class, 'practitioner_location')
            ->using(PractitionerLocation::class)
            ->withTimestamps();
    }

    /**
     * @return HasMany<LocationClosure, $this>
     */
    public function closures(): HasMany
    {
        return $this->hasMany(LocationClosure::class);
    }

    /**
     * @return HasMany<WorkingHour, $this>
     */
    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }
}
