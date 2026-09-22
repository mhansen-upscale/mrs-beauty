<?php

declare(strict_types=1);

namespace Tests\Feature\Kalender;

use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarPrivacyMode;
use App\Enums\CalendarProvider;
use App\Models\CalendarConnection;
use App\Models\Organization;
use App\Models\Practitioner;
use Carbon\CarbonImmutable;
use Tests\Feature\Termine\Szenario;

/**
 * Eine Praxis mit Slots, einem Kontakt und einem verbundenen Outlook-Kalender.
 *
 * Wie Kalenderaufbau, nur fuer den zweiten Anbieter -- absichtlich daneben und
 * nicht darueber: die beiden Gegenstellen haben nichts gemein, und ein
 * gemeinsamer Aufbau haette genau das verdeckt.
 */
final class Graphaufbau
{
    public readonly Organization $organisation;

    public readonly Graphattrappe $graph;

    public readonly Szenario $szenario;

    public readonly CalendarConnection $verbindung;

    public function __construct(?Organization $organisation = null, string $zone = 'Europe/Berlin')
    {
        $this->organisation = alsMandant($organisation);

        $this->graph = new Graphattrappe;
        $this->graph->installiere();

        $this->szenario = new Szenario;
        $this->verbindung = self::verbinde($this->szenario->aufbau->behandler, $zone);
    }

    /** Eine zweite Verbindung fuer denselben Behandler -- anderer Anbieter. */
    public static function verbinde(Practitioner $behandler, string $zone = 'Europe/Berlin'): CalendarConnection
    {
        $verbindung = new CalendarConnection;
        $verbindung->practitioner_id = $behandler->getKey();
        $verbindung->provider = CalendarProvider::Microsoft;
        $verbindung->status = CalendarConnectionStatus::Active;
        $verbindung->privacy_mode = CalendarPrivacyMode::BusyOnly;
        $verbindung->calendar_id = 'AAMkAGI2-kalender';
        $verbindung->account_email = 'praxis@outlook.test';
        $verbindung->access_token = 'zugang';
        $verbindung->refresh_token = 'aktualisierung';
        $verbindung->access_expires_at = CarbonImmutable::now()->addHour();
        $verbindung->calendar_timezone = $zone;
        $verbindung->channel_id = 'abo-0';
        $verbindung->channel_token = 'geheimnis-graph';
        $verbindung->channel_resource_id = '/me/events';
        $verbindung->channel_expires_at = CarbonImmutable::now()->addHours(60);
        $verbindung->save();

        return $verbindung;
    }
}
