<?php

declare(strict_types=1);

namespace Tests\Feature\Kalender;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Google, so wie dieses Paket es braucht.
 *
 * Kein Mitschnitt und keine feste Antwortliste, sondern eine kleine
 * Gegenstelle: sie merkt sich, was geschrieben wurde, und laesst sich in die
 * Zustaende versetzen, auf die es ankommt -- verfallenes Delta-Token,
 * entzogener Zugang, extern geloeschtes Event.
 */
final class Googleattrappe
{
    /** @var list<array<string, mixed>> */
    public array $ereignisse = [];

    public ?string $syncToken = 'sync-2';

    /** Der naechste Abruf mit Delta-Token antwortet mit 410. */
    public bool $tokenVerfallen = false;

    /** Jeder Aufruf scheitert mit "Zugang entzogen". */
    public bool $zugangEntzogen = false;

    /** Ein PATCH auf ein Event antwortet mit 404 -- drueben geloescht. */
    public bool $eventFehlt = false;

    /** @var list<array<string, mixed>> */
    public array $angelegt = [];

    /** @var list<array{id: string, daten: array<string, mixed>}> */
    public array $aktualisiert = [];

    /** @var list<string> */
    public array $geloescht = [];

    /** @var list<array<string, mixed>> */
    public array $abonniert = [];

    /** @var list<array{id: string, resourceId: string}> */
    public array $beendet = [];

    public int $abrufe = 0;

    /** Das zuletzt mitgeschickte Delta-Token -- null heisst Vollabgleich. */
    public ?string $letztesToken = null;

    public function installiere(): void
    {
        Http::fake(fn (Request $anfrage): mixed => $this->antwort($anfrage));
    }

    /**
     * Ein Event, wie Google es liefert.
     *
     * @param  array<string, mixed>  $ueberschreibung
     * @return array<string, mixed>
     */
    public static function ereignis(string $id, string $beginn, string $ende, array $ueberschreibung = []): array
    {
        return array_merge([
            'id' => $id,
            'status' => 'confirmed',
            'summary' => 'Zahnarzt Dr. Weber',
            'start' => ['dateTime' => $beginn],
            'end' => ['dateTime' => $ende],
        ], $ueberschreibung);
    }

    private function antwort(Request $anfrage): mixed
    {
        // **Nur Google.** Laravel reicht eine Anfrage an die naechste
        // Attrappe weiter, wenn diese hier null liefert -- nur so lassen sich
        // zwei Anbieter in einem Test nebeneinander stellen. Ohne die Grenze
        // beantwortet die zuerst eingerichtete Attrappe auch die Anfragen der
        // anderen, und der Test prueft nichts.
        if (! str_contains($anfrage->url(), 'googleapis.com') && ! str_contains($anfrage->url(), 'accounts.google.com')) {
            return null;
        }

        $pfad = (string) parse_url($anfrage->url(), PHP_URL_PATH);
        $methode = $anfrage->method();

        if (str_contains($anfrage->url(), '/revoke')) {
            return Http::response([]);
        }

        if (str_contains($anfrage->url(), 'oauth2') && str_contains($anfrage->url(), '/token')) {
            return $this->zugangEntzogen
                ? Http::response(['error' => 'invalid_grant'], 400)
                : Http::response([
                    'access_token' => 'zugang-neu',
                    'refresh_token' => 'aktualisierung-neu',
                    'expires_in' => 3600,
                ]);
        }

        if ($this->zugangEntzogen) {
            return Http::response(['error' => []], 401);
        }

        if (str_ends_with($pfad, '/channels/stop')) {
            /** @var array<string, mixed> $daten */
            $daten = $anfrage->data();
            $this->beendet[] = [
                'id' => (string) ($daten['id'] ?? ''),
                'resourceId' => (string) ($daten['resourceId'] ?? ''),
            ];

            return Http::response([]);
        }

        if (str_ends_with($pfad, '/events/watch')) {
            /** @var array<string, mixed> $daten */
            $daten = $anfrage->data();
            $this->abonniert[] = $daten;

            return Http::response([
                'resourceId' => 'ressource-'.count($this->abonniert),
                'expiration' => (string) (CarbonImmutable::now()->addDays(30)->getTimestampMs()),
            ]);
        }

        if (str_ends_with($pfad, '/events')) {
            return $methode === 'POST' ? $this->lege($anfrage) : $this->liste($anfrage);
        }

        if (preg_match('#/events/([^/]+)$#', $pfad, $treffer) === 1) {
            return $this->einzeln($methode, rawurldecode($treffer[1]), $anfrage);
        }

        // Alles Uebrige ist die Abfrage des Kalenders selbst.
        return Http::response([
            'id' => 'praxis@example.com',
            'timeZone' => 'Europe/Berlin',
        ]);
    }

    private function liste(Request $anfrage): mixed
    {
        $this->abrufe++;

        parse_str((string) parse_url($anfrage->url(), PHP_URL_QUERY), $abfrage);

        $token = $abfrage['syncToken'] ?? null;
        $this->letztesToken = is_string($token) ? $token : null;

        if ($this->tokenVerfallen && is_string($token)) {
            $this->tokenVerfallen = false;

            return Http::response(['error' => []], 410);
        }

        return Http::response([
            'items' => $this->ereignisse,
            'nextSyncToken' => $this->syncToken,
        ]);
    }

    private function lege(Request $anfrage): mixed
    {
        /** @var array<string, mixed> $daten */
        $daten = $anfrage->data();
        $this->angelegt[] = $daten;

        return Http::response(['id' => 'extern-'.count($this->angelegt)]);
    }

    private function einzeln(string $methode, string $kennung, Request $anfrage): mixed
    {
        if ($methode === 'DELETE') {
            $this->geloescht[] = $kennung;

            return Http::response([], 204);
        }

        if ($this->eventFehlt) {
            return Http::response(['error' => []], 404);
        }

        /** @var array<string, mixed> $daten */
        $daten = $anfrage->data();
        $this->aktualisiert[] = ['id' => $kennung, 'daten' => $daten];

        return Http::response(['id' => $kennung]);
    }
}
