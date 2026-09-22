<?php

declare(strict_types=1);

namespace Tests\Feature\Warteliste;

use App\Datenschutz\Einwilligungen;
use App\Enums\ChannelType;
use App\Enums\ConsentType;
use App\Enums\WaitlistStatus;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\WaitlistEntry;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;
use Tests\Feature\Verfuegbarkeit\Aufbau;

/**
 * Eine Praxis mit Slots und Wartenden.
 *
 * Der Slot, um den es geht, liegt am Mittwoch, dem 13.01.2027, um 09:00 Uhr
 * Ortszeit -- dieselbe Buehne wie in WP-10 und WP-11.
 */
final class Wartelistenaufbau
{
    public readonly Aufbau $praxis;

    public readonly Organization $organisation;

    public const TAG = '2027-01-13';

    public function __construct()
    {
        $this->organisation = alsMandant(organisation('Demo-Praxis'));

        $this->praxis = new Aufbau;
        $this->praxis->erzeugeSlots(self::TAG, self::TAG);
    }

    /**
     * Ein Wartender, der auf alles passt -- sofern nichts anderes gesagt ist.
     *
     * @param  list<array<string, string>>|null  $zeitfenster
     */
    public function wartender(
        string $nachname = 'Mueller',
        int $vorlaufStunden = 1,
        int $prioritaet = 0,
        ?string $telefon = null,
        bool $mitEinwilligung = true,
        bool $alleStandorte = true,
        int $wochentage = 127,
        ?array $zeitfenster = null,
        ?string $frueheste = null,
        ?string $spaeteste = null,
    ): WaitlistEntry {
        $kontakt = Contact::create([
            'first_name' => 'Anna',
            'last_name' => $nachname,
            'phone' => $telefon ?? '+49 170 '.random_int(1000000, 9999999),
        ]);

        $identitaet = ChannelIdentity::create([
            'channel' => ChannelType::WhatsApp,
            'external_id' => (string) $kontakt->phone,
            'contact_id' => $kontakt->getKey(),
        ]);

        if ($mitEinwilligung) {
            app(Einwilligungen::class)->erteile(
                $identitaet,
                ConsentType::ServiceMessages,
                'warteliste-test',
                'Ich möchte über freie Termine informiert werden.',
            );
        }

        $eintrag = new WaitlistEntry;
        $eintrag->contact_id = $kontakt->getKey();
        $eintrag->appointment_type_id = $this->praxis->art->getKey();
        $eintrag->status = WaitlistStatus::Active;
        $eintrag->all_locations = $alleStandorte;
        $eintrag->earliest_date = CarbonImmutable::parse($frueheste ?? '2027-01-01');
        $eintrag->latest_date = CarbonImmutable::parse($spaeteste ?? '2027-12-31');
        $eintrag->weekday_mask = $wochentage;
        $eintrag->time_windows = $zeitfenster;
        $eintrag->min_notice_hours = $vorlaufStunden;
        $eintrag->priority = $prioritaet;
        $eintrag->expires_at = CarbonImmutable::parse('2027-12-31 23:59:59');
        $eintrag->save();

        return $eintrag;
    }

    /** Der Slot, um den es geht. */
    public function slot(string $ortszeit = '09:00'): Slotvorschlag
    {
        return Slotvorschlag::ab(
            $this->praxis->art,
            $this->praxis->behandler,
            $this->praxis->standort,
            CarbonImmutable::parse(self::TAG.' '.$ortszeit, $this->praxis->standort->timezone)->utc(),
        );
    }
}
