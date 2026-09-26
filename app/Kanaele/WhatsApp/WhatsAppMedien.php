<?php

declare(strict_types=1);

namespace App\Kanaele\WhatsApp;

use App\Kanaele\Kanalfehler;
use App\Models\ChannelConnection;
use App\Support\Fehlereinordnung;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Holt eine Datei aus dem Chat ueber den Media-Endpunkt der Cloud API
 * (offen seit WP-20a).
 *
 * **Zwei Schritte, beide mit Token.** Die Zustellung traegt nur eine Kennung;
 * `GET /{media-id}` liefert eine kurzlebige Adresse, und erst die liefert
 * die Datei. Die Adresse gilt Minuten, die Kennung Wochen -- deshalb wird mit
 * der Kennung wiederholt, nie mit einer gemerkten Adresse.
 *
 * **Das Token geht nur an Meta.** Die zweite Adresse stammt aus einer Antwort;
 * sie wird gegen `mrs.channels.whatsapp.media_hosts` geprueft, bevor das
 * Token mitgeht.
 */
final class WhatsAppMedien
{
    /**
     * @return array{inhalt: string, mime: string}|null Null heisst: gibt es
     *                                                  nicht (mehr), zu gross oder nicht bei Meta
     *
     * @throws Kanalfehler wenn Meta ausfaellt oder bremst -- das bessert sich
     *                     durch Wiederholen
     */
    public function hole(ChannelConnection $verbindung, string $kennung): ?array
    {
        $basis = rtrim((string) config('mrs.meta.graph_url'), '/');
        $version = (string) config('mrs.meta.api_version');

        $auskunft = $this->anfrage($verbindung)->get($basis.'/'.$version.'/'.rawurlencode($kennung));

        if ($auskunft->failed()) {
            return $this->scheitere($auskunft);
        }

        $adresse = data_get($auskunft->json(), 'url');
        $groesse = data_get($auskunft->json(), 'file_size');
        $mime = data_get($auskunft->json(), 'mime_type');
        $grenze = (int) config('mrs.channels.whatsapp.max_media_bytes');

        if (! is_string($adresse) || ! $this->beiMeta($adresse)) {
            return null;
        }

        // Nicht erst holen, um dann zu verwerfen: die Groesse steht in der
        // Auskunft.
        if (is_numeric($groesse) && (int) $groesse > $grenze) {
            return null;
        }

        $datei = $this->anfrage($verbindung)->get($adresse);

        if ($datei->failed()) {
            return $this->scheitere($datei);
        }

        $inhalt = $datei->body();

        if ($inhalt === '' || strlen($inhalt) > $grenze) {
            return null;
        }

        return ['inhalt' => $inhalt, 'mime' => is_string($mime) ? $mime : 'application/octet-stream'];
    }

    /**
     * Was Meta ablehnt, weil es die Datei nicht (mehr) gibt, ist kein Fehler.
     * Was sich durch Warten bessert -- oder die Verbindung betrifft --, schon.
     *
     * @throws Kanalfehler
     */
    private function scheitere(Response $antwort): null
    {
        $einordnung = Fehlereinordnung::ausMetaAntwort($antwort->status(), (array) $antwort->json());

        if ($einordnung->wiederholen || $einordnung->zustand !== null) {
            throw new Kanalfehler($einordnung);
        }

        return null;
    }

    private function beiMeta(string $adresse): bool
    {
        $teile = parse_url($adresse);

        if (! is_array($teile) || ($teile['scheme'] ?? '') !== 'https' || ! is_string($teile['host'] ?? null)) {
            return false;
        }

        $host = mb_strtolower($teile['host']);

        foreach ((array) config('mrs.channels.whatsapp.media_hosts', []) as $erlaubt) {
            if (! is_string($erlaubt)) {
                continue;
            }

            if ($host === $erlaubt || str_ends_with($host, '.'.$erlaubt)) {
                return true;
            }
        }

        return false;
    }

    private function anfrage(ChannelConnection $verbindung): PendingRequest
    {
        return Http::withToken((string) $verbindung->access_token)
            ->timeout(30)
            ->retry(2, 200, function (Throwable $ausnahme): bool {
                if ($ausnahme instanceof ConnectionException) {
                    return true;
                }

                return $ausnahme instanceof RequestException && $ausnahme->response->serverError();
            }, throw: false);
    }
}
