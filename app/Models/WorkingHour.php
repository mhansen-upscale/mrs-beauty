<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Weekday;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein wiederkehrendes Arbeitszeitfenster (Bedingung V1 der Verfuegbarkeit).
 *
 * **Wochentag plus Ortszeit, nicht UTC.** "Montags 9 bis 17 Uhr" ist keine
 * Zeitspanne, sondern eine Regel. In UTC gespeichert stuende sie nach der
 * Zeitumstellung eine Stunde daneben -- die Praxis oeffnete ein halbes Jahr
 * lang zur falschen Zeit.
 *
 * Mehrere Fenster je Tag sind vorgesehen. Die Mittagspause ist die Luecke
 * dazwischen, kein eigener Datensatz.
 *
 * @property string $practitioner_id
 * @property string $location_id
 * @property Weekday $weekday
 * @property string $starts_at Ortszeit als HH:MM:SS
 * @property string $ends_at Ortszeit als HH:MM:SS
 */
class WorkingHour extends TenantModel
{
    use Auditable;

    protected $fillable = ['practitioner_id', 'location_id', 'weekday', 'starts_at', 'ends_at'];

    /** @var list<string> */
    protected $hidden = ['practitioner_id', 'location_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['weekday', 'starts_at', 'ends_at'];
    }

    /**
     * Faellt dieser Zeitpunkt in das Fenster?
     *
     * Der Zeitpunkt wird in die Zone **des Standorts** gebracht, nicht in die
     * des Servers. Das Ende ist ausschliessend: ein Fenster 09:00 bis 17:00
     * endet um 17:00, es enthaelt diesen Moment nicht mehr.
     */
    public function enthaelt(CarbonInterface $zeitpunkt, Location $standort): bool
    {
        $ortszeit = $standort->ortszeit($zeitpunkt);

        if (Weekday::fromDate($ortszeit) !== $this->weekday) {
            return false;
        }

        $uhrzeit = $ortszeit->format('H:i:s');

        return $uhrzeit >= $this->starts_at && $uhrzeit < $this->ends_at;
    }

    /** Ueberschneidet sich dieses Fenster mit einem anderen am selben Tag? */
    public function ueberschneidetSich(string $beginn, string $ende): bool
    {
        return $beginn < $this->ends_at && $ende > $this->starts_at;
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
