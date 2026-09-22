<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Weekday;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\PractitionerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Eine Behandlerin oder ein Behandler.
 *
 * **Nicht dasselbe wie ein Benutzerkonto.** Eine Praxis fuehrt Behandler im
 * Kalender, die sich nie anmelden. Die Verbindung ist optional.
 *
 * Der Name ist bewusst **nicht** verschluesselt: die Praxis veroeffentlicht ihn
 * selbst auf der Buchungsseite (WP-12). Was oeffentlich ist, muss nicht gegen
 * den eigenen Betreiber geschuetzt werden.
 *
 * @property string|null $title
 * @property string $first_name
 * @property string $last_name
 * @property bool $is_active
 * @property string|null $user_id
 * @property string|null $avatar_path
 */
class Practitioner extends TenantModel
{
    use Auditable;

    /** @use HasFactory<PractitionerFactory> */
    use HasFactory;

    protected $fillable = ['title', 'first_name', 'last_name', 'user_id', 'is_active', 'avatar_path'];

    /** @var list<string> */
    protected $hidden = ['user_id'];

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
        return ['is_active'];
    }

    public function name(): string
    {
        return trim(($this->title ?? '').' '.$this->first_name.' '.$this->last_name);
    }

    /**
     * Die Adresse des Bildes -- oder null.
     *
     * **Oeffentlich erreichbar, unverschluesselt**, und das ist dieselbe
     * Entscheidung wie beim Namen: die Praxis veroeffentlicht es selbst auf
     * der Buchungsseite. Was oeffentlich ist, muss nicht gegen den eigenen
     * Betreiber geschuetzt werden.
     */
    public function avatarUrl(): ?string
    {
        return is_string($this->avatar_path) && $this->avatar_path !== ''
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    /** Die Initialen -- der Rueckfall, wenn es kein Bild gibt. */
    public function initialen(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    /**
     * Arbeitet dieser Behandler zu diesem Zeitpunkt an diesem Standort?
     *
     * Die einzige fachliche Abfrage dieses Pakets. Sie fasst V1, V2 und V3
     * zusammen -- die Verfuegbarkeits-Engine aus WP-10 setzt darauf auf, statt
     * die drei Bedingungen erneut zu formulieren.
     *
     * Die Umrechnung geht von UTC in die Ortszeit des Standorts. Diese
     * Richtung ist immer eindeutig: auch am Umstellungstag hat jeder
     * UTC-Zeitpunkt genau eine Ortszeit. Die andere Richtung -- Ortszeit nach
     * UTC -- ist es nicht, und genau dort liegt die Arbeit von WP-10.
     */
    public function arbeitetAm(CarbonInterface $zeitpunkt, Location $standort): bool
    {
        if (! $this->is_active || ! $standort->is_active) {
            return false;
        }

        $ortszeit = $standort->ortszeit($zeitpunkt);
        $tag = Weekday::fromDate($ortszeit);
        $uhrzeit = $ortszeit->format('H:i:s');

        // V1 -- Arbeitszeitfenster
        $imFenster = $this->workingHours()
            ->where('location_id', $standort->getKey())
            ->where('weekday', $tag->value)
            ->where('starts_at', '<=', $uhrzeit)
            ->where('ends_at', '>', $uhrzeit)
            ->exists();

        if (! $imFenster) {
            return false;
        }

        // V2 -- Abwesenheit
        if ($this->istAbwesendAm($zeitpunkt)) {
            return false;
        }

        // V3 -- Schliesszeit des Standorts
        return ! $standort->istGeschlossenAm($zeitpunkt);
    }

    public function istAbwesendAm(CarbonInterface $zeitpunkt): bool
    {
        return $this->absences()
            ->where('starts_at', '<=', $zeitpunkt)
            ->where('ends_at', '>', $zeitpunkt)
            ->exists();
    }

    /**
     * @return BelongsToMany<Location, $this, PractitionerLocation>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'practitioner_location')
            ->using(PractitionerLocation::class)
            ->withTimestamps();
    }

    /**
     * @return HasMany<WorkingHour, $this>
     */
    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    /**
     * @return HasMany<Absence, $this>
     */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
