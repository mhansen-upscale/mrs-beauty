<?php

declare(strict_types=1);

namespace App\Kalender\Microsoft;

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
use Throwable;

/**
 * Microsoft Graph, auf das reduziert, was dieses Paket braucht.
 *
 * Gebaut **neben** der Google-Umsetzung und nicht darueber:
 * docs/integrationen/kalender.md verlangt beide Anbieter, bevor abstrahiert
 * wird. Was die beiden wirklich teilen, steht danach in App\Kalender.
 */
final class MicrosoftKalender
{
    /**
     * Hoechstlaufzeit eines Abonnements auf /me/events, mit Sicherheitsabstand.
     *
     * Graph laesst gut 70 Stunden zu und weist alles darueber ab. Der Abstand
     * faengt ab, dass die Uhr des Anbieters anders geht als unsere.
     */
    private const ABO_MINUTEN = 4100;

    public function __construct(private readonly MicrosoftZugang $zugang) {}

    /** Kennung, Adresse und Zone des Postfachs. */
    public function kalender(CalendarConnection $verbindung): Kalenderangaben
    {
        $konto = $this->geprueft($this->anfrage($verbindung)->get($this->basis().'/me'));
        $kalender = $this->geprueft($this->anfrage($verbindung)->get($this->basis().'/me/calendar'));

        $adresse = data_get($konto, 'mail') ?? data_get($konto, 'userPrincipalName');
        $kennung = data_get($kalender, 'id');

        return new Kalenderangaben(
            kennung: is_string($kennung) && $kennung !== '' ? $kennung : 'primary',
            adresse: is_string($adresse) ? $adresse : '',
            zone: $this->zone($verbindung),
        );
    }

    /**
     * Ereignisse abrufen -- als Delta, wenn ein Zeiger vorliegt.
     *
     * `calendarView/delta` loest Serien auf, wie `singleEvents` bei Google.
     * Der Zeitraum steht nur beim Vollabgleich in der Abfrage; der Delta-Link
     * traegt ihn selbst.
     *
     * **Erweiterungen kommen hier nicht mit.** Die Delta-Abfrage kennt kein
     * `$expand`, die Eigenmarkierung ist auf diesem Weg also nicht lesbar --
     * die zweite Haelfte von R1 steht deshalb in Rueckabgleich und vergleicht
     * gegen die Kennungen, die wir selbst geschrieben haben.
     */
    public function ereignisse(
        CalendarConnection $verbindung,
        ?string $zeiger,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): Ereignisseite {
        $organisation = Uuid::toString($verbindung->organization_id);
        $zone = $verbindung->calendar_timezone;

        $adresse = $zeiger ?? $this->basis().'/me/calendarView/delta?'.http_build_query([
            'startDateTime' => $von->toIso8601ZuluString(),
            'endDateTime' => $bis->toIso8601ZuluString(),
        ]);

        $ereignisse = [];
        $neuerZeiger = null;

        do {
            $antwort = $this->anfrage($verbindung)
                ->withHeaders(['Prefer' => 'odata.maxpagesize=200'])
                ->get($adresse);

            // Wie bei Google: ein verfallener Zeiger ist kein Fehler, sondern
            // der vorgesehene Weg zum Vollabgleich. Graph nennt dazu einen
            // eigenen Fehlercode.
            if ($antwort->status() === 410 || $this->verlangtNeuaufbau($antwort)) {
                throw SyncTokenVerfallen::machVollabgleich();
            }

            $daten = $this->geprueft($antwort);

            foreach ((array) data_get($daten, 'value', []) as $roh) {
                if (is_array($roh)) {
                    $ereignisse[] = Ereignisleser::aus($roh, $zone, $organisation);
                }
            }

            // **Direkter Zugriff, nicht data_get.** Der Punkt in
            // '@odata.nextLink' ist Teil des Schluessels; data_get liest ihn
            // als Pfad und sucht ein Feld 'nextLink' unter '@odata'. Das
            // findet nichts, wirft nichts -- und der Sync macht ab dann bei
            // jedem Lauf einen Vollabgleich, ohne dass etwas fehlschlaegt.
            $weiter = $daten['@odata.nextLink'] ?? null;
            $adresse = is_string($weiter) ? $weiter : null;

            // Der neue Zeiger steht nur auf der letzten Seite.
            $abschluss = $daten['@odata.deltaLink'] ?? null;
            $neuerZeiger = is_string($abschluss) ? $abschluss : $neuerZeiger;
        } while ($adresse !== null);

        return new Ereignisseite($ereignisse, $neuerZeiger);
    }

    /**
     * @param  array<string, mixed>  $ereignis
     * @return string Die Kennung des angelegten Events
     */
    public function lege(CalendarConnection $verbindung, array $ereignis): string
    {
        $antwort = $this->anfrage($verbindung)->post($this->basis().'/me/events', $ereignis);

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
        // Erweiterungen lassen sich nicht mitpatchen -- sie haengen am Event
        // und bleiben dort. Erneut zu schicken waere ein Fehler.
        unset($ereignis['extensions']);

        $antwort = $this->anfrage($verbindung)
            ->patch($this->basis().'/me/events/'.rawurlencode($kennung), $ereignis);

        // R3: extern geloescht heisst nicht im System geloescht.
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
            ->delete($this->basis().'/me/events/'.rawurlencode($kennung));

        if ($antwort->status() === 404 || $antwort->status() === 410) {
            return;
        }

        $this->geprueft($antwort);
    }

    /**
     * Bestellt Zustellungen.
     *
     * `clientState` ist unseres und kommt bei jeder Zustellung zurueck -- die
     * "Signatur" aus Entscheidung A14, wie das Kanal-Token bei Google.
     *
     * **Graph ruft die Adresse sofort auf** und erwartet den mitgegebenen
     * `validationToken` binnen Sekunden als reinen Text zurueck. Ohne diese
     * Antwort entsteht das Abonnement gar nicht.
     */
    public function beobachte(CalendarConnection $verbindung, string $geheimnis, string $adresse): Abonnement
    {
        $antwort = $this->anfrage($verbindung)->post($this->basis().'/subscriptions', [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => $adresse,
            'resource' => '/me/events',
            'clientState' => $geheimnis,
            'expirationDateTime' => $this->ablauf()->toIso8601ZuluString(),
        ]);

        return $this->ausAntwort($antwort);
    }

    /**
     * Verlaengert ein bestehendes Abonnement.
     *
     * Der Unterschied zu Google: dort muss ein neuer Kanal bestellt und der
     * alte beendet werden, hier genuegt ein neues Ablaufdatum. Die Kennung
     * bleibt -- und damit auch das Geheimnis.
     */
    public function erneuere(CalendarConnection $verbindung, string $geheimnis, string $adresse): Abonnement
    {
        $kennung = $verbindung->channel_id;

        if (! is_string($kennung) || $kennung === '') {
            return $this->beobachte($verbindung, $geheimnis, $adresse);
        }

        $antwort = $this->anfrage($verbindung)->patch(
            $this->basis().'/subscriptions/'.rawurlencode($kennung),
            ['expirationDateTime' => $this->ablauf()->toIso8601ZuluString()],
        );

        // Ein Abonnement, das es drueben nicht mehr gibt, laesst sich nicht
        // verlaengern -- dann wird eben ein neues bestellt.
        if ($antwort->status() === 404) {
            return $this->beobachte($verbindung, $geheimnis, $adresse);
        }

        return $this->ausAntwort($antwort);
    }

    public function beende(CalendarConnection $verbindung): void
    {
        $kennung = $verbindung->channel_id;

        if (! is_string($kennung) || $kennung === '') {
            return;
        }

        $antwort = $this->anfrage($verbindung)
            ->delete($this->basis().'/subscriptions/'.rawurlencode($kennung));

        if ($antwort->status() === 404) {
            return;
        }

        $this->geprueft($antwort);
    }

    private function ausAntwort(Response $antwort): Abonnement
    {
        $daten = $this->geprueft($antwort);

        $kennung = data_get($daten, 'id');
        $ablauf = data_get($daten, 'expirationDateTime');

        if (! is_string($kennung) || $kennung === '') {
            throw KalenderNichtErreichbar::mitStatus($antwort->status());
        }

        $ressource = data_get($daten, 'resource');

        return new Abonnement(
            kennung: $kennung,
            ressource: is_string($ressource) ? $ressource : '/me/events',
            // Ohne verwertbare Angabe lieber zu frueh erneuern als zu spaet.
            laeuftAb: is_string($ablauf)
                ? CarbonImmutable::parse($ablauf)->utc()
                : CarbonImmutable::now()->addHour(),
        );
    }

    /** Die Zone des Postfachs. Angenommen wird sie nicht. */
    private function zone(CalendarConnection $verbindung): string
    {
        try {
            $antwort = $this->anfrage($verbindung)->get($this->basis().'/me/mailboxSettings/timeZone');
            $wert = data_get($this->geprueft($antwort), 'value');
        } catch (Throwable) {
            return 'UTC';
        }

        return is_string($wert) && $wert !== '' ? $wert : 'UTC';
    }

    private function ablauf(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(self::ABO_MINUTEN);
    }

    /** Graph meldet einen verfallenen Zeiger auch mit eigenem Fehlercode. */
    private function verlangtNeuaufbau(Response $antwort): bool
    {
        if ($antwort->successful()) {
            return false;
        }

        $code = data_get($antwort->json(), 'error.code');

        return in_array($code, ['resyncRequired', 'syncStateNotFound'], true);
    }

    private function anfrage(CalendarConnection $verbindung): PendingRequest
    {
        return Http::withToken($this->zugang->token($verbindung))
            ->acceptJson()
            ->timeout(20)
            // Nur Ausfaelle werden wiederholt, keine Aussagen: ein 410 sagt
            // "bau das Delta neu auf", ein 404 beim Loeschen "ist schon weg".
            // Beide werden beim zweiten Mal nicht besser (WP-14).
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
        return rtrim((string) config('services.microsoft.graph_url'), '/');
    }
}
