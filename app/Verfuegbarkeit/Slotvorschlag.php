<?php

declare(strict_types=1);

namespace App\Verfuegbarkeit;

use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\Practitioner;
use Carbon\CarbonImmutable;

/**
 * Ein buchbarer Terminvorschlag.
 *
 * Traegt **zwei** Zeitraeume, und das ist der Punkt: `blockedFrom` bis
 * `blockedUntil` wird im Kalender belegt, `startsAt` bis `endsAt` wird dem
 * Kontakt angezeigt. Der Unterschied ist die Ruestzeit (WP-09).
 */
final class Slotvorschlag
{
    public function __construct(
        public readonly AppointmentType $art,
        public readonly Practitioner $behandler,
        public readonly Location $standort,
        public readonly CarbonImmutable $blockedFrom,
        public readonly CarbonImmutable $blockedUntil,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {}

    public static function ab(
        AppointmentType $art,
        Practitioner $behandler,
        Location $standort,
        CarbonImmutable $blockBeginn,
    ): self {
        $start = $blockBeginn->addMinutes($art->buffer_before_minutes);

        return new self(
            art: $art,
            behandler: $behandler,
            standort: $standort,
            blockedFrom: $blockBeginn,
            blockedUntil: $blockBeginn->addMinutes($art->belegteDauer()),
            startsAt: $start,
            endsAt: $start->addMinutes($art->duration_minutes),
        );
    }

    /** Die angezeigte Startzeit in der Ortszeit des Standorts. */
    public function ortszeit(): CarbonImmutable
    {
        return $this->standort->ortszeit($this->startsAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'local_time' => $this->ortszeit()->format('H:i'),
            'local_date' => $this->ortszeit()->toDateString(),
            'practitioner' => $this->behandler->uuid,
            'practitioner_name' => $this->behandler->name(),
            'location' => $this->standort->uuid,
            'location_name' => $this->standort->name,
        ];
    }
}
