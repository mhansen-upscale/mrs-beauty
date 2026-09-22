<?php

declare(strict_types=1);

namespace Tests\Feature\Kalender;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Graph, so wie dieses Paket es braucht.
 *
 * Schwester der Googleattrappe, und die Unterschiede zwischen beiden sind
 * genau die, auf die es ankommt: Zeiten ohne Versatz, ein Delta-Zeiger als
 * vollstaendige Adresse, ein Abonnement, das sich verlaengern laesst.
 */
final class Graphattrappe
{
    /** @var list<array<string, mixed>> */
    public array $ereignisse = [];

    public string $deltaLink = 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=zwei';

    /** Der naechste Abruf mit Zeiger antwortet mit 410 und resyncRequired. */
    public bool $zeigerVerfallen = false;

    public bool $zugangEntzogen = false;

    /** Ein PATCH auf ein Event antwortet mit 404 -- drueben geloescht. */
    public bool $eventFehlt = false;

    /** Ein PATCH auf das Abonnement antwortet mit 404. */
    public bool $abonnementFehlt = false;

    /** @var list<array<string, mixed>> */
    public array $angelegt = [];

    /** @var list<array{id: string, daten: array<string, mixed>}> */
    public array $aktualisiert = [];

    /** @var list<string> */
    public array $geloescht = [];

    /** @var list<array<string, mixed>> */
    public array $abonniert = [];

    /** @var list<array<string, mixed>> */
    public array $verlaengert = [];

    /** @var list<string> */
    public array $beendet = [];

    public int $abrufe = 0;

    /** Der zuletzt benutzte Delta-Zeiger -- null heisst Vollabgleich. */
    public ?string $letzterZeiger = null;

    public string $postfachzone = 'Europe/Berlin';

    public function installiere(): void
    {
        Http::fake(fn (Request $anfrage): mixed => $this->antwort($anfrage));
    }

    /**
     * Ein Event, wie Graph es liefert: Ortszeit **ohne** Versatz, daneben die
     * Zone.
     *
     * @param  array<string, mixed>  $ueberschreibung
     * @return array<string, mixed>
     */
    public static function ereignis(
        string $id,
        string $beginn,
        string $ende,
        string $zone = 'UTC',
        array $ueberschreibung = [],
    ): array {
        return array_merge([
            'id' => $id,
            'subject' => 'Zahnarzt Dr. Weber',
            'isCancelled' => false,
            'isAllDay' => false,
            'showAs' => 'busy',
            'start' => ['dateTime' => $beginn, 'timeZone' => $zone],
            'end' => ['dateTime' => $ende, 'timeZone' => $zone],
        ], $ueberschreibung);
    }

    private function antwort(Request $anfrage): mixed
    {
        $url = $anfrage->url();

        // Nur Microsoft -- siehe Googleattrappe. Wer null liefert, laesst die
        // naechste Attrappe ran.
        if (! str_contains($url, 'graph.microsoft.com') && ! str_contains($url, 'login.microsoftonline.com')) {
            return null;
        }

        $pfad = (string) parse_url($url, PHP_URL_PATH);
        $methode = $anfrage->method();

        if (str_contains($url, 'login.microsoftonline.com')) {
            return $this->zugangEntzogen
                ? Http::response(['error' => 'invalid_grant'], 400)
                : Http::response([
                    'access_token' => 'zugang-neu',
                    'refresh_token' => 'aktualisierung-neu',
                    'expires_in' => 3600,
                ]);
        }

        if ($this->zugangEntzogen) {
            return Http::response(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
        }

        if (str_contains($pfad, '/calendarView/delta')) {
            return $this->delta($url);
        }

        if (str_ends_with($pfad, '/subscriptions')) {
            /** @var array<string, mixed> $daten */
            $daten = $anfrage->data();
            $this->abonniert[] = $daten;

            return $this->abonnement('abo-'.count($this->abonniert), $daten);
        }

        if (preg_match('#/subscriptions/([^/]+)$#', $pfad, $treffer) === 1) {
            return $this->abonnementpflege($methode, rawurldecode($treffer[1]), $anfrage);
        }

        if (str_ends_with($pfad, '/me/events')) {
            /** @var array<string, mixed> $daten */
            $daten = $anfrage->data();
            $this->angelegt[] = $daten;

            return Http::response(['id' => 'graph-'.count($this->angelegt)]);
        }

        if (preg_match('#/me/events/([^/]+)$#', $pfad, $treffer) === 1) {
            return $this->einzeln($methode, rawurldecode($treffer[1]), $anfrage);
        }

        if (str_ends_with($pfad, '/mailboxSettings/timeZone')) {
            return Http::response(['value' => $this->postfachzone]);
        }

        if (str_ends_with($pfad, '/me/calendar')) {
            return Http::response(['id' => 'AAMkAGI2-kalender', 'name' => 'Kalender']);
        }

        // Alles Uebrige ist die Abfrage des Kontos.
        return Http::response(['mail' => 'praxis@outlook.test', 'userPrincipalName' => 'praxis@outlook.test']);
    }

    private function delta(string $url): mixed
    {
        $this->abrufe++;

        $this->letzterZeiger = str_contains($url, 'deltatoken') ? $url : null;

        if ($this->zeigerVerfallen && $this->letzterZeiger !== null) {
            $this->zeigerVerfallen = false;

            return Http::response(['error' => ['code' => 'resyncRequired']], 410);
        }

        return Http::response([
            'value' => $this->ereignisse,
            '@odata.deltaLink' => $this->deltaLink,
        ]);
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function abonnement(string $kennung, array $daten): mixed
    {
        return Http::response([
            'id' => $kennung,
            'resource' => $daten['resource'] ?? '/me/events',
            // Graph laesst keine drei Tage zu -- genau der Unterschied, der
            // den kuerzeren Erneuerungsvorlauf noetig macht.
            'expirationDateTime' => CarbonImmutable::now()->addMinutes(4100)->toIso8601ZuluString(),
        ]);
    }

    private function abonnementpflege(string $methode, string $kennung, Request $anfrage): mixed
    {
        if ($methode === 'DELETE') {
            $this->beendet[] = $kennung;

            return Http::response([], 204);
        }

        if ($this->abonnementFehlt) {
            return Http::response(['error' => ['code' => 'ResourceNotFound']], 404);
        }

        /** @var array<string, mixed> $daten */
        $daten = $anfrage->data();
        $this->verlaengert[] = ['id' => $kennung] + $daten;

        return $this->abonnement($kennung, ['resource' => '/me/events']);
    }

    private function einzeln(string $methode, string $kennung, Request $anfrage): mixed
    {
        if ($methode === 'DELETE') {
            $this->geloescht[] = $kennung;

            return Http::response([], 204);
        }

        if ($this->eventFehlt) {
            return Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404);
        }

        /** @var array<string, mixed> $daten */
        $daten = $anfrage->data();
        $this->aktualisiert[] = ['id' => $kennung, 'daten' => $daten];

        return Http::response(['id' => $kennung]);
    }
}
