<?php

declare(strict_types=1);

namespace App\Kalender\Google;

use App\Kalender\KalenderNichtErreichbar;
use App\Kalender\ZugangEntzogen;
use App\Kalender\Zugangsdaten;
use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * OAuth gegen Google: Zustimmung holen, Code tauschen, Zugang erneuern.
 *
 * Getrennt vom Kalenderzugriff, weil die beiden verschiedene Lebensdauern
 * haben: ein Zugangstoken gilt eine Stunde, eine Verbindung Jahre. Die Klasse
 * hier ist die einzige Stelle, die Token schreibt.
 */
final class GoogleZugang
{
    /** Nur der Kalender, nichts sonst. Mehr Rechte wuerden wir nicht nutzen. */
    private const BEREICH = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly';

    /**
     * Die Adresse der Zustimmungsseite.
     *
     * `access_type=offline` und `prompt=consent` sind beide noetig: ohne das
     * erste kommt kein Aktualisierungsschluessel, ohne das zweite kommt er
     * bei einer erneuten Verbindung nicht wieder.
     */
    public function weiterleitung(string $state): string
    {
        return (string) config('services.google.auth_url').'?'.http_build_query([
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => (string) config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => self::BEREICH,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function tausche(string $code): Zugangsdaten
    {
        $antwort = Http::asForm()->post((string) config('services.google.token_url'), [
            'code' => $code,
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'redirect_uri' => (string) config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if ($antwort->status() === 400 || $antwort->status() === 401) {
            throw ZugangEntzogen::neuVerbinden();
        }

        if (! $antwort->successful()) {
            throw KalenderNichtErreichbar::mitStatus($antwort->status());
        }

        return $this->ausAntwort($antwort->json());
    }

    /**
     * Ein gueltiges Zugangstoken -- erneuert es, wenn noetig.
     *
     * Erneuert wird fuenf Minuten vor Ablauf. Ein Token, das waehrend eines
     * langen Abgleichs ablaeuft, erzeugt sonst einen Fehler mitten im Lauf.
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
     * `invalid_grant` heisst: der Nutzer hat den Zugriff im Google-Konto
     * entzogen oder das Konto existiert nicht mehr. Das ist kein
     * Uebertragungsfehler und keine Wiederholung wert -- die Verbindung ist
     * tot und muss neu hergestellt werden (R4).
     */
    public function erneuere(CalendarConnection $verbindung): string
    {
        if (! is_string($verbindung->refresh_token) || $verbindung->refresh_token === '') {
            throw ZugangEntzogen::neuVerbinden();
        }

        $antwort = Http::asForm()->post((string) config('services.google.token_url'), [
            'refresh_token' => $verbindung->refresh_token,
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if ($antwort->status() === 400 || $antwort->status() === 401) {
            throw ZugangEntzogen::neuVerbinden();
        }

        if (! $antwort->successful()) {
            throw KalenderNichtErreichbar::mitStatus($antwort->status());
        }

        $daten = $this->ausAntwort($antwort->json());

        $verbindung->access_token = $daten->zugang;
        $verbindung->access_expires_at = $daten->laeuftAb;
        $verbindung->save();

        return $daten->zugang;
    }

    /** Beim Trennen: das Recht drueben zurueckgeben, nicht nur hier loeschen. */
    public function widerrufe(CalendarConnection $verbindung): void
    {
        $merkmal = $verbindung->refresh_token ?? $verbindung->access_token;

        if (! is_string($merkmal) || $merkmal === '') {
            return;
        }

        // Ein fehlgeschlagener Widerruf darf das Trennen nicht aufhalten: die
        // Verbindung ist danach ohnehin weg.
        Http::asForm()->post((string) config('services.google.revoke_url'), ['token' => $merkmal]);
    }

    private function ausAntwort(mixed $daten): Zugangsdaten
    {
        $daten = is_array($daten) ? $daten : [];

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
}
