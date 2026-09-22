<?php

declare(strict_types=1);

namespace App\Werbung\Meta;

use App\Support\Fehlereinordnung;
use App\Werbung\Werbefehler;
use App\Werbung\Werbetoken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Die Login-Strecke fuer ein Werbekonto.
 *
 * **Facebook Login for Business, nicht der gewoehnliche Login.** Der
 * Unterschied liegt in der Konfigurations-ID: sie bestimmt, welche
 * Berechtigungen abgefragt werden und welche Art Token Meta ausstellt. Ein
 * Nutzertoken funktioniert in der Entwicklung sofort und stirbt in Produktion
 * mit dem ersten Mitarbeiter, der die Praxis verlaesst -- danach steht die
 * Werbung still, ohne dass jemand weiss, warum (Entscheidung B1).
 *
 * Getrennt vom Lesen, weil beide verschiedene Lebensdauern haben: ein Token
 * gilt Wochen, eine Verbindung Jahre. Diese Klasse ist die einzige, die
 * Token schreibt.
 */
final class Werbezugang
{
    public function weiterleitung(string $state): string
    {
        $anfrage = [
            'client_id' => (string) config('mrs.meta.app_id'),
            'redirect_uri' => (string) config('services.meta.redirect'),
            'response_type' => 'code',
            'state' => $state,
        ];

        $konfiguration = config('services.meta.config_id');

        if (is_string($konfiguration) && $konfiguration !== '') {
            // Der Weg ueber die Login-Konfiguration: Meta stellt dann ein
            // Systembenutzer-Token der Partnerschaft aus.
            $anfrage['config_id'] = $konfiguration;
        } else {
            // Ohne Konfiguration bleibt der gewoehnliche Weg mit Bereichen.
            // Er traegt die Entwicklung und die Demo des App Review, nicht
            // den Betrieb.
            $bereiche = config('mrs.ads.scopes');
            $anfrage['scope'] = implode(',', is_array($bereiche) ? $bereiche : []);
        }

        return (string) config('services.meta.login_url').'?'.http_build_query($anfrage);
    }

    public function tausche(string $code): Werbetoken
    {
        $antwort = Http::acceptJson()->get(
            rtrim((string) config('mrs.meta.graph_url'), '/')
            .'/'.(string) config('mrs.meta.api_version').'/oauth/access_token',
            [
                'client_id' => (string) config('mrs.meta.app_id'),
                'client_secret' => (string) config('mrs.meta.app_secret'),
                'redirect_uri' => (string) config('services.meta.redirect'),
                'code' => $code,
            ]
        );

        if ($antwort->failed()) {
            throw new Werbefehler(
                Fehlereinordnung::ausMetaAntwort($antwort->status(), (array) $antwort->json())
            );
        }

        $daten = $antwort->json();
        $daten = is_array($daten) ? $daten : [];

        $zugang = $daten['access_token'] ?? null;

        if (! is_string($zugang) || $zugang === '') {
            throw new Werbefehler(new Fehlereinordnung('token_invalid', wiederholen: false, zustand: null));
        }

        // `expires_in` fehlt bei langlebigen Token. Dann ist der Ablauf
        // unbekannt -- und unbekannt heisst null, nicht "gilt ewig". Ein
        // geschaetzter Ablauf waere eine Warnung, die nie kommt.
        $sekunden = is_numeric($daten['expires_in'] ?? null) ? (int) $daten['expires_in'] : null;

        return new Werbetoken(
            zugang: $zugang,
            laeuftAb: $sekunden === null ? null : CarbonImmutable::now()->addSeconds($sekunden),
        );
    }
}
