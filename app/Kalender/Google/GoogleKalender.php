<?php

declare(strict_types=1);

namespace App\Kalender\Google;

use App\Kalender\Abonnement;
use App\Kalender\Ereignisseite;
use App\Kalender\Kalenderangaben;
use App\Kalender\KalenderNichtErreichbar;
use App\Kalender\SyncTokenVerfallen;
use App\Kalender\ZugangEntzogen;
use App\Models\CalendarConnection;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Die Google-Calendar-API, auf das reduziert, was dieses Paket braucht.
 *
 * **Absichtlich ohne Anbieter-Interface.** docs/integrationen/kalender.md ist
 * an dieser Stelle ausdruecklich: "Erst beide umsetzen, dann abstrahieren.
 * Ein gemeinsames Interface vor der zweiten Umsetzung zu bauen fuehrt zu einer
 * Abstraktion, die auf keinen von beiden richtig passt." WP-15 baut daneben.
 *
 * Kein SDK: der genutzte Ausschnitt ist klein, und ein SDK, das seine eigenen
 * Ausnahmen wirft, verdeckt genau die Unterscheidung, auf die es hier ankommt
 * -- 410 ist ein Zustand, 401 ein Ausfall, 404 beim Loeschen ein Erfolg.
 */
final class GoogleKalender
{
    public function __construct(private readonly GoogleZugang $zugang) {}

    /**
     * Kennung und Zone des Kalenders.
     *
     * Beim Verbinden steht in `calendar_id` zunaechst "primary". Diese
     * Abfrage macht daraus die echte Kennung -- und liefert die Zone, in der
     * ganztaegige Events ausgewertet werden. Eine Zone anzunehmen ist der
     * zuverlaessigste Weg zu einem Blocker, der einen Tag daneben liegt.
     */
    public function kalender(CalendarConnection $verbindung): Kalenderangaben
    {
        $daten = $this->geprueft($this->anfrage($verbindung)->get($this->kalenderpfad($verbindung)));

        $kennung = data_get($daten, 'id');
        $zone = data_get($daten, 'timeZone');
        $kennung = is_string($kennung) && $kennung !== '' ? $kennung : (string) $verbindung->calendar_id;

        // Bei Google ist die Kennung des Kalenders in aller Regel die Adresse
        // des Kontos -- eines von beiden gibt es nicht ohne das andere.
        return new Kalenderangaben(
            kennung: $kennung,
            adresse: $kennung,
            zone: is_string($zone) && $zone !== '' ? $zone : 'UTC',
        );
    }

    /**
     * Ereignisse abrufen -- als Delta, wenn ein Token vorliegt.
     *
     * `singleEvents=true` loest Serien auf; ohne das kaeme eine
     * Wiederholungsregel zurueck, die wir selbst auswerten muessten.
     * `showDeleted=true` ist beim Delta noetig: eine Absage kommt sonst gar
     * nicht an, und ihr Blocker bliebe stehen.
     *
     * Der Zeitraum gilt nur beim Vollabgleich. Google verbietet ihn zusammen
     * mit einem `syncToken` -- das Delta traegt seinen Zeitraum selbst.
     */
    public function ereignisse(
        CalendarConnection $verbindung,
        ?string $zeiger,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): Ereignisseite {
        $organisation = Uuid::toString($verbindung->organization_id);
        $zone = $verbindung->calendar_timezone;

        $ereignisse = [];
        $seite = null;
        $neuesToken = null;

        do {
            $abfrage = array_filter([
                'singleEvents' => 'true',
                'showDeleted' => 'true',
                'maxResults' => 250,
                'pageToken' => $seite,
                'syncToken' => $zeiger,
                'timeMin' => $zeiger === null ? $von->toRfc3339String() : null,
                'timeMax' => $zeiger === null ? $bis->toRfc3339String() : null,
            ], fn (mixed $wert): bool => $wert !== null);

            $antwort = $this->anfrage($verbindung)->get($this->kalenderpfad($verbindung).'/events', $abfrage);

            // 410 ist kein Fehler, sondern der vorgesehene Weg zum
            // Vollabgleich. Wer ihn als Ausfall behandelt, hat einen Sync,
            // der nach ein paar Wochen Ruhe stillsteht.
            if ($antwort->status() === 410) {
                throw SyncTokenVerfallen::machVollabgleich();
            }

            $daten = $this->geprueft($antwort);

            foreach ((array) data_get($daten, 'items', []) as $roh) {
                if (is_array($roh)) {
                    $ereignisse[] = Ereignisleser::aus($roh, $zone, $organisation);
                }
            }

            $seite = data_get($daten, 'nextPageToken');
            $seite = is_string($seite) ? $seite : null;

            // Das Delta-Token steht nur auf der letzten Seite.
            $abschluss = data_get($daten, 'nextSyncToken');
            $neuesToken = is_string($abschluss) ? $abschluss : $neuesToken;
        } while ($seite !== null);

        return new Ereignisseite($ereignisse, $neuesToken);
    }

    /**
     * @param  array<string, mixed>  $ereignis
     * @return string Die Kennung des angelegten Events
     */
    public function lege(CalendarConnection $verbindung, array $ereignis): string
    {
        $antwort = $this->anfrage($verbindung)->post($this->kalenderpfad($verbindung).'/events', $ereignis);

        $kennung = data_get($this->geprueft($antwort), 'id');

        if (! is_string($kennung) || $kennung === '') {
            throw KalenderNichtErreichbar::mitStatus($antwort->status());
        }

        return $kennung;
    }

    /**
     * @param  array<string, mixed>  $ereignis
     * @return bool false, wenn das Event drueben nicht mehr existiert
     */
    public function aktualisiere(CalendarConnection $verbindung, string $kennung, array $ereignis): bool
    {
        $antwort = $this->anfrage($verbindung)
            ->patch($this->kalenderpfad($verbindung).'/events/'.rawurlencode($kennung), $ereignis);

        // R3: extern geloescht heisst nicht im System geloescht. Der Aufrufer
        // legt das Event neu an, der Termin bleibt unberuehrt.
        if ($antwort->status() === 404 || $antwort->status() === 410) {
            return false;
        }

        $this->geprueft($antwort);

        return true;
    }

    /** Ein Event, das drueben schon weg ist, ist ein Erfolg -- kein Fehler. */
    public function entferne(CalendarConnection $verbindung, string $kennung): void
    {
        $antwort = $this->anfrage($verbindung)
            ->delete($this->kalenderpfad($verbindung).'/events/'.rawurlencode($kennung));

        if ($antwort->status() === 404 || $antwort->status() === 410) {
            return;
        }

        $this->geprueft($antwort);
    }

    /**
     * Bestellt Zustellungen fuer diesen Kalender.
     *
     * Das Token ist unseres und kommt bei jeder Zustellung zurueck. Es ist die
     * "Signatur" aus Entscheidung A14 -- Google unterschreibt nicht, es
     * spiegelt.
     */
    public function beobachte(
        CalendarConnection $verbindung,
        string $geheimnis,
        string $adresse,
    ): Abonnement {
        // **Google vergibt die Kanalkennung nicht -- wir tun es.** Sie muss
        // mandantenuebergreifend eindeutig sein: die Zustellung kommt ohne
        // Anmeldung an und traegt nur sie.
        $kanal = (string) Str::uuid();

        $antwort = $this->anfrage($verbindung)->post($this->kalenderpfad($verbindung).'/events/watch', [
            'id' => $kanal,
            'type' => 'web_hook',
            'address' => $adresse,
            'token' => $geheimnis,
        ]);

        $daten = $this->geprueft($antwort);

        $ressource = data_get($daten, 'resourceId');
        $ablauf = data_get($daten, 'expiration');

        if (! is_string($ressource) || $ressource === '') {
            throw KalenderNichtErreichbar::mitStatus($antwort->status());
        }

        return new Abonnement(
            kennung: $kanal,
            ressource: $ressource,
            // Google liefert Millisekunden seit Epoche. Ohne Angabe die
            // Hoechstlaufzeit anzunehmen waere die gefaehrlichere Annahme --
            // lieber zu frueh erneuern.
            laeuftAb: is_numeric($ablauf)
                ? CarbonImmutable::createFromTimestampMs((int) $ablauf, 'UTC')
                : CarbonImmutable::now()->addDay(),
        );
    }

    /**
     * Erneuern heisst bei Google: einen neuen Kanal bestellen und den alten
     * beenden.
     *
     * **In dieser Reihenfolge.** Andersherum entstuende dazwischen eine
     * Luecke, in der Aenderungen nicht zugestellt werden -- und Zustellungen
     * kommen nicht nach.
     */
    public function erneuere(
        CalendarConnection $verbindung,
        string $geheimnis,
        string $adresse,
    ): Abonnement {
        $alterKanal = $verbindung->channel_id;
        $alteRessource = $verbindung->channel_resource_id;

        $abonnement = $this->beobachte($verbindung, $geheimnis, $adresse);

        if (is_string($alterKanal) && is_string($alteRessource)) {
            $this->beendeKanal($verbindung, $alterKanal, $alteRessource);
        }

        return $abonnement;
    }

    public function beende(CalendarConnection $verbindung): void
    {
        if (! is_string($verbindung->channel_id) || ! is_string($verbindung->channel_resource_id)) {
            return;
        }

        $this->beendeKanal($verbindung, $verbindung->channel_id, $verbindung->channel_resource_id);
    }

    /**
     * Einen bestimmten Kanal abbestellen.
     *
     * Getrennt vom Modellzustand, weil beim Erneuern der neue Kanal schon
     * darin stehen kann, waehrend der alte noch abbestellt werden muss.
     */
    private function beendeKanal(CalendarConnection $verbindung, string $kanal, string $ressource): void
    {
        $antwort = $this->anfrage($verbindung)->post($this->basis().'/channels/stop', [
            'id' => $kanal,
            'resourceId' => $ressource,
        ]);

        if ($antwort->status() === 404) {
            return;
        }

        $this->geprueft($antwort);
    }

    private function anfrage(CalendarConnection $verbindung): PendingRequest
    {
        return Http::withToken($this->zugang->token($verbindung))
            ->acceptJson()
            ->timeout(20)
            // **Nur Ausfaelle werden wiederholt, keine Aussagen.** Ein 410
            // bedeutet "das Delta-Token gilt nicht mehr" -- eine Wiederholung
            // verschluckt die Aussage, weil der zweite Versuch mit demselben
            // Token schon eine andere Antwort bekommen kann. Dasselbe gilt fuer
            // 401 und 404: keiner der drei wird beim zweiten Mal besser.
            ->retry(2, 200, function (Throwable $ausnahme): bool {
                if ($ausnahme instanceof ConnectionException) {
                    return true;
                }

                return $ausnahme instanceof RequestException
                    && $ausnahme->response->serverError();
            }, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function geprueft(Response $antwort): array
    {
        if ($antwort->status() === 401 || $antwort->status() === 403) {
            throw ZugangEntzogen::neuVerbinden();
        }

        if (! $antwort->successful()) {
            throw KalenderNichtErreichbar::mitStatus($antwort->status());
        }

        $daten = $antwort->json();

        return is_array($daten) ? $daten : [];
    }

    private function basis(): string
    {
        return rtrim((string) config('services.google.calendar_url'), '/');
    }

    private function kalenderpfad(CalendarConnection $verbindung): string
    {
        return $this->basis().'/calendars/'.rawurlencode((string) $verbindung->calendar_id);
    }
}
