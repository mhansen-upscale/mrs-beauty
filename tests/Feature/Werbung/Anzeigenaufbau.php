<?php

declare(strict_types=1);

namespace Tests\Feature\Werbung;

use App\Compliance\Pruefgegenstand;
use App\Compliance\Pruefung;
use App\Datenschutz\Anhangspeicher;
use App\Enums\AttachmentContext;
use App\Enums\Role;
use App\Enums\SyncState;
use App\Enums\Vorschlagsstatus;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\AdSuggestion;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Werbung\Verwaltung\Anzeigenschaltung;
use Carbon\CarbonImmutable;

/**
 * Eine Praxis mit Werbekonto, Kampagne und freigegebenem Entwurf.
 *
 * Als Klasse und nicht als Testfunktion: Pest teilt Hilfsfunktionen ueber
 * alle Dateien, und zwei gleichnamige lassen den Lauf platzen.
 */
final class Anzeigenaufbau
{
    public readonly Werbeaufbau $werbung;

    public readonly AdCampaign $kampagne;

    public readonly AdSet $gruppe;

    public function __construct(?Organization $organisation = null)
    {
        $this->werbung = new Werbeaufbau($organisation);

        // Ohne Facebook-Seite kein Creative -- sie ist der Absender.
        $this->werbung->konto->page_external_id = '778899';
        $this->werbung->konto->save();

        $standort = Location::factory()->create(['name' => 'Hauptstandort', 'city' => 'Hamburg', 'is_active' => true]);

        $kampagne = new AdCampaign;
        $kampagne->ad_account_id = $this->werbung->konto->getKey();
        $kampagne->external_id = 'kampagne-extern';
        $kampagne->client_token = 'abcdefghij';
        $kampagne->managed_by_us = true;
        $kampagne->name = 'Anfragen sammeln · Oktober 2026 · Hamburg [abcdefghij]';
        $kampagne->objective = 'OUTCOME_LEADS';
        $kampagne->status = 'PAUSED';
        $kampagne->effective_status = 'PAUSED';
        $kampagne->daily_budget = 2500;
        $kampagne->sync_state = SyncState::Synced;
        $kampagne->save();

        $gruppe = new AdSet;
        $gruppe->ad_account_id = $this->werbung->konto->getKey();
        $gruppe->ad_campaign_id = $kampagne->getKey();
        $gruppe->external_id = 'gruppe-extern';
        $gruppe->client_token = 'abcdefghij';
        $gruppe->managed_by_us = true;
        $gruppe->name = 'Zielgruppe [abcdefghij]';
        $gruppe->status = 'PAUSED';
        $gruppe->effective_status = 'PAUSED';
        $gruppe->location_id = $standort->getKey();
        $gruppe->radius_km = 15;
        $gruppe->age_min = 30;
        $gruppe->age_max = 60;
        $gruppe->sync_state = SyncState::Synced;
        $gruppe->save();

        $this->kampagne = $kampagne;
        $this->gruppe = $gruppe;
    }

    public function vorschlagMitGrafik(
        Vorschlagsstatus $status = Vorschlagsstatus::Freigegeben,
        string $ueberschrift = 'In Ruhe beraten lassen',
    ): AdSuggestion {
        $vorschlag = $this->vorschlagOhneGrafik($status, $ueberschrift);

        app(Anhangspeicher::class)->lege(
            traeger: $vorschlag,
            inhalt: 'bilddaten',
            dateiname: 'anzeige-'.$vorschlag->uuid.'.png',
            kontext: AttachmentContext::BrandReference,
            wer: User::factory()->fuer($this->werbung->organisation, Role::Owner)->create(),
        );

        return $vorschlag->fresh() ?? $vorschlag;
    }

    /**
     * **`description` bleibt mit Absicht leer.**
     *
     * Das Feld ist nullable, und genau dieser Fall liess Meta am 23.09.2026
     * jedes Creative ablehnen -- `"description": null` beantwortet es mit
     * "Invalid parameter". Aufgefallen ist es erst auf der Staging-Umgebung,
     * weil `Http::fake()` jeden Payload widerspruchslos annimmt.
     *
     * Wer einen Testfall mit Beschreibung braucht, setzt sie am Rueckgabewert
     * -- der Vorgabefall bleibt der leere. Ein Aufbau, der jedes optionale
     * Feld fuellt, prueft immer nur den guten Fall.
     */
    public function vorschlagOhneGrafik(
        Vorschlagsstatus $status = Vorschlagsstatus::Freigegeben,
        string $ueberschrift = 'In Ruhe beraten lassen',
    ): AdSuggestion {
        $vorschlag = new AdSuggestion;
        $vorschlag->week = CarbonImmutable::now()->startOfWeek();
        $vorschlag->headline = $ueberschrift;
        $vorschlag->body = 'Wir nehmen uns Zeit für Ihre Fragen.';
        $vorschlag->cta = 'Termin anfragen';
        $vorschlag->status = $status;
        $vorschlag->save();

        app(Pruefung::class)->pruefeUndHalteFest(
            $vorschlag,
            new Pruefgegenstand(text: $ueberschrift.' Wir nehmen uns Zeit für Ihre Fragen.'),
        );

        return $vorschlag;
    }

    public function geplanteAnzeige(string $ueberschrift = 'In Ruhe beraten lassen'): Ad
    {
        return app(Anzeigenschaltung::class)->plane(
            $this->gruppe,
            $this->vorschlagMitGrafik(ueberschrift: $ueberschrift),
        );
    }
}
