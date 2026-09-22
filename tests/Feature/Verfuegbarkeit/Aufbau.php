<?php

declare(strict_types=1);

namespace Tests\Feature\Verfuegbarkeit;

use App\Enums\Weekday;
use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\Practitioner;
use App\Verfuegbarkeit\SlotErzeuger;
use Carbon\CarbonImmutable;

/**
 * Ein kleines Praxis-Szenario, auf dem die Testfaelle aus
 * docs/fachlogik/verfuegbarkeit.md aufsetzen.
 */
final class Aufbau
{
    public Location $standort;

    public Practitioner $behandler;

    public AppointmentType $art;

    public function __construct(
        string $zeitzone = 'Europe/Berlin',
        string $von = '09:00:00',
        string $bis = '17:00:00',
        int $dauer = 30,
        int $ruestzeitDavor = 0,
        int $ruestzeitDanach = 0,
        int $vorlaufStunden = 0,
    ) {
        $this->standort = Location::factory()->inZone($zeitzone)->create();
        $this->behandler = Practitioner::factory()->create();
        $this->behandler->locations()->attach($this->standort);

        foreach ([Weekday::Montag, Weekday::Dienstag, Weekday::Mittwoch, Weekday::Donnerstag, Weekday::Freitag] as $tag) {
            $this->behandler->workingHours()->create([
                'location_id' => $this->standort->getKey(),
                'weekday' => $tag,
                'starts_at' => $von,
                'ends_at' => $bis,
            ]);
        }

        $this->art = AppointmentType::factory()
            ->mitRuestzeit($ruestzeitDavor, $ruestzeitDanach)
            ->mitVorlauf($vorlaufStunden)
            ->create(['duration_minutes' => $dauer]);

        $this->art->practitioners()->attach($this->behandler);
        $this->art->locations()->attach($this->standort);
    }

    /**
     * @return array{angelegt: int, entfernt: int}
     */
    public function erzeugeSlots(string $von, string $bis): array
    {
        return app(SlotErzeuger::class)->erzeuge(
            CarbonImmutable::parse($von, 'UTC'),
            CarbonImmutable::parse($bis, 'UTC'),
        );
    }
}
