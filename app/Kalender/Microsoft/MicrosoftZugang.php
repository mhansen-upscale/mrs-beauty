<?php

declare(strict_types=1);

namespace App\Kalender\Microsoft;

use App\Kalender\KalenderNichtErreichbar;
use App\Kalender\ZugangEntzogen;
use App\Kalender\Zugangsdaten;
use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * OAuth gegen Microsoft Identity: Zustimmung holen, Code tauschen, Zugang
 * erneuern.
 *
 * Getrennt vom Kalenderzugriff, aus demselben Grund wie bei Google: ein
 * Zugangstoken gilt eine Stunde, eine Verbindung Jahre. Diese Klasse ist die
 * einzige Stelle, die Token schreibt.
 */
final class MicrosoftZugang
{
    /**
     * Nur der Kalender -- und `offline_access`, ohne das es keinen
     * Aktualisierungsschluessel gibt.
     */
    private const BEREICH = 'offline_access openid email Calendars.ReadWrite MailboxSettings.Read';

    public function weiterleitung(string $state): string
    {
        return $this->adresse('auth_url').'?'.http_build_query([
            'client_id' => (string) config('services.microsoft.client_id'),
            'redirect_uri' => (string) config('services.microsoft.redirect'),
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => self::BEREICH,
            'state' => $state,
        ]);
    }

    public function tausche(string $code): Zugangsdaten
    {
        return $this->hole([
            'code' => $code,
            'redirect_uri' => (string) config('services.microsoft.redirect'),
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * Ein gueltiges Zugangstoken -- erneuert es, wenn noetig.
     *
     * Fuenf Minuten vor Ablauf, damit ein langer Abgleich nicht mittendrin
     * auf ein abgelaufenes Token laeuft.
     */
    public function token(CalendarConnection $verbindung, ?CarbonImmutable $jetzt = null): string
    {
        $jetzt ??= CarbonImmutable::now();

        if (! $verbindung->zugangLaeuftAus($jetzt)) {
            return (string) $verbindung->access_token;
        }

        return $this->erneuere($verbindung);
    }

    /**
     * Holt ein neues Zugangstoken.
     *
     * `invalid_grant` heisst auch hier: der Zugriff wurde entzogen oder das
     * Konto gibt es nicht mehr. Keine Wiederholung wert -- die Verbindung ist
     * tot und muss neu hergestellt werden (R4).
     */
    public function erneuere(CalendarConnection $verbindung): string
    {
        if (! is_string($verbindung->refresh_token) || $verbindung->refresh_token === '') {
            throw ZugangEntzogen::neuVerbinden();
        }

        $daten = $this->hole([
            'refresh_token' => $verbindung->refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => self::BEREICH,
        ]);

        $verbindung->access_token = $daten->zugang;
        $verbindung->access_expires_at = $daten->laeuftAb;

        // **Microsoft dreht den Aktualisierungsschluessel mit.** Anders als
        // bei Google kommt bei jeder Erneuerung ein neuer, und der alte
        // verfaellt. Wer ihn nicht mitschreibt, hat eine Verbindung, die genau
        // einmal funktioniert.
        if ($daten->aktualisierung !== null) {
            $verbindung->refresh_token = $daten->aktualisierung;
        }

        $verbindung->save();

        return $daten->zugang;
    }

    /**
     * Graph kennt keinen Widerrufsendpunkt wie Google.
     *
     * Der Zugriff wird im Microsoft-Konto entzogen, nicht von uns. Beim
     * Trennen loeschen wir das Abonnement und die Token -- mehr ist von hier
     * aus nicht moeglich, und so zu tun als ob waere schlechter als es zu
     * sagen.
     */
    public function widerrufe(CalendarConnection $verbindung): void
    {
        // Nichts zu tun.
    }

    /**
     * @param  array<string, string>  $felder
     */
    private function hole(array $felder): Zugangsdaten
    {
        $antwort = Http::asForm()->post($this->adresse('token_url'), array_merge([
            'client_id' => (string) config('services.microsoft.client_id'),
            'client_secret' => (string) config('services.microsoft.client_secret'),
        ], $felder));

        if ($antwort->status() === 400 || $antwort->status() === 401) {
            throw ZugangEntzogen::neuVerbinden();
        }

        if (! $antwort->successful()) {
            throw KalenderNichtErreichbar::mitStatus($antwort->status());
        }

        /** @var array<string, mixed> $daten */
        $daten = is_array($antwort->json()) ? $antwort->json() : [];

        $zugang = $daten['access_token'] ?? null;

        if (! is_string($zugang) || $zugang === '') {
            throw ZugangEntzogen::neuVerbinden();
        }

        $sekunden = is_numeric($daten['expires_in'] ?? null) ? (int) $daten['expires_in'] : 3600;
        $aktualisierung = $daten['refresh_token'] ?? null;

        return new Zugangsdaten(
            zugang: $zugang,
            aktualisierung: is_string($aktualisierung) && $aktualisierung !== '' ? $aktualisierung : null,
            laeuftAb: CarbonImmutable::now()->addSeconds($sekunden),
        );
    }

    /** Die Mandantenkennung steht in der Adresse, nicht in der Nutzlast. */
    private function adresse(string $schluessel): string
    {
        return str_replace(
            '{tenant}',
            (string) config('services.microsoft.tenant', 'common'),
            (string) config('services.microsoft.'.$schluessel),
        );
    }
}
