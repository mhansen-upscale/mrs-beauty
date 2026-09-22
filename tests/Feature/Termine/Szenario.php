<?php

declare(strict_types=1);

namespace Tests\Feature\Termine;

use App\Models\Contact;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\Feature\Verfuegbarkeit\Aufbau;

/**
 * Eine Praxis mit erzeugten Slots und einem Kontakt.
 *
 * Setzt auf Tests\Feature\Verfuegbarkeit\Aufbau auf: WP-11 prueft dieselben
 * Bedingungen, nur aus der anderen Richtung -- nicht "was wird angeboten",
 * sondern "was darf gebucht werden".
 */
final class Szenario
{
    public readonly Aufbau $aufbau;

    public readonly Contact $kontakt;

    /** Mittwoch. */
    public const TAG = '2027-01-13';

    public function __construct(
        string $von = '09:00:00',
        string $bis = '17:00:00',
        int $dauer = 30,
        int $ruestzeitDavor = 0,
        int $ruestzeitDanach = 0,
        int $vorlaufStunden = 0,
        string $zeitzone = 'Europe/Berlin',
    ) {
        $this->aufbau = new Aufbau(
            zeitzone: $zeitzone,
            von: $von,
            bis: $bis,
            dauer: $dauer,
            ruestzeitDavor: $ruestzeitDavor,
            ruestzeitDanach: $ruestzeitDanach,
            vorlaufStunden: $vorlaufStunden,
        );

        $this->aufbau->erzeugeSlots(self::TAG, self::TAG);

        $this->kontakt = Contact::factory()->create();
    }

    /** Der Zeitpunkt, an dem in diesen Tests "jetzt" ist: der Vortag. */
    public function jetzt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-01-12 00:00:00', 'UTC');
    }

    /** Ein angebotener Vorschlag des Tages. */
    public function vorschlag(int $index = 0, ?CarbonImmutable $jetzt = null): Slotvorschlag
    {
        $vorschlaege = app(Verfuegbarkeit::class)->freieStartzeiten(
            art: $this->aufbau->art,
            von: CarbonImmutable::parse(self::TAG.' 00:00:00', 'UTC'),
            bis: CarbonImmutable::parse(self::TAG.' 00:00:00', 'UTC')->addDay(),
            jetzt: $jetzt ?? $this->jetzt(),
        );

        return $vorschlaege[$index]
            ?? throw new RuntimeException("Kein Vorschlag an Stelle {$index}.");
    }

    /**
     * Ein Vorschlag an einem frei gewaehlten Zeitpunkt -- auch dort, wo gar
     * nichts angeboten wird. Genau das ist der Fall, den Uebersteuern meint.
     */
    public function vorschlagAb(string $utc): Slotvorschlag
    {
        return Slotvorschlag::ab(
            $this->aufbau->art,
            $this->aufbau->behandler,
            $this->aufbau->standort,
            CarbonImmutable::parse($utc, 'UTC'),
        );
    }
}
