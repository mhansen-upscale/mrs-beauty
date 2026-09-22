<?php

declare(strict_types=1);

namespace Tests\Feature\Kalender;

use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarPrivacyMode;
use App\Enums\CalendarProvider;
use App\Models\CalendarConnection;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Termine\Szenario;

/**
 * Eine Praxis mit Slots, einem Kontakt und einem verbundenen Kalender.
 *
 * Setzt auf Tests\Feature\Termine\Szenario auf -- WP-14 prueft dieselbe
 * Verfuegbarkeit, nur aus einer dritten Richtung: nicht "was wird angeboten"
 * und nicht "was darf gebucht werden", sondern "was ist von aussen belegt".
 */
final class Kalenderaufbau
{
    public readonly Organization $organisation;

    public readonly Googleattrappe $google;

    public readonly Szenario $szenario;

    public readonly CalendarConnection $verbindung;

    public function __construct(?Organization $organisation = null, string $zone = 'Europe/Berlin')
    {
        $this->organisation = alsMandant($organisation);

        // Die Gegenstelle steht, bevor das erste Mal gebucht wird: eine
        // Buchung schreibt ueber die Queue 'sync' sofort nach draussen.
        $this->google = new Googleattrappe;
        $this->google->installiere();

        $this->szenario = new Szenario;

        $verbindung = new CalendarConnection;
        $verbindung->practitioner_id = $this->szenario->aufbau->behandler->getKey();
        $verbindung->provider = CalendarProvider::Google;
        $verbindung->status = CalendarConnectionStatus::Active;
        $verbindung->privacy_mode = CalendarPrivacyMode::BusyOnly;
        $verbindung->calendar_id = 'praxis@example.com';
        $verbindung->account_email = 'praxis@example.com';
        $verbindung->access_token = 'zugang';
        $verbindung->refresh_token = 'aktualisierung';
        $verbindung->access_expires_at = CarbonImmutable::now()->addHour();
        $verbindung->calendar_timezone = $zone;
        $verbindung->channel_id = (string) Str::uuid();
        $verbindung->channel_token = 'kanal-token';
        $verbindung->channel_resource_id = 'ressource-0';
        $verbindung->channel_expires_at = CarbonImmutable::now()->addDays(30);
        $verbindung->save();

        $this->verbindung = $verbindung;
    }
}
