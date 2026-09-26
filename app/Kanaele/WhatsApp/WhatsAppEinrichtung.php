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
 * Prueft eine eingetragene WhatsApp-Verbindung -- und macht sie
 * empfangsbereit (offen seit WP-20b: "Einen Kanal einzurichten geht bisher
 * nur ueber die Datenbank").
 *
 * Drei Schritte, jeder mit dem Token der Praxis:
 *
 * 1. **Die Rufnummer lesen.** Stimmen Rufnummern-ID und Token zusammen?
 * 2. **Die Zustellungen abonnieren** (`POST /{waba}/subscribed_apps`). Ohne das
 *    kommt keine Nachricht an -- und das faellt erst auf, wenn eine Patientin
 *    fragt, warum niemand antwortet.
 * 3. **Die Templates abgleichen**, damit der Posteingang sie sofort kennt.
 *
 * **Nie im Anfragezyklus** (B2, Regel 4): aufgerufen aus WhatsAppPruefen.
 */
final class WhatsAppEinrichtung
{
    public function __construct(private readonly Templateabgleich $templates) {}

    /**
     * @throws Kanalfehler mit der Einordnung aus der Fehlertabelle
     */
    public function pruefe(ChannelConnection $verbindung): void
    {
        $basis = rtrim((string) config('mrs.meta.graph_url'), '/').'/'.config('mrs.meta.api_version');

        $nummer = $this->anfrage($verbindung)->get(
            $basis.'/'.rawurlencode((string) $verbindung->sender_id),
            ['fields' => 'display_phone_number,verified_name'],
        );

        $this->pruefeAntwort($nummer);

        $abo = $this->anfrage($verbindung)->post($basis.'/'.rawurlencode($verbindung->external_id).'/subscribed_apps');

        $this->pruefeAntwort($abo);

        $this->templates->gleicheAb($verbindung);
    }

    /**
     * @throws Kanalfehler
     */
    private function pruefeAntwort(Response $antwort): void
    {
        // Meta meldet manche Ablehnung mit 200 und `error` im Rumpf.
        if ($antwort->failed() || data_get($antwort->json(), 'error') !== null) {
            throw new Kanalfehler(Fehlereinordnung::ausMetaAntwort($antwort->status(), (array) $antwort->json()));
        }
    }

    private function anfrage(ChannelConnection $verbindung): PendingRequest
    {
        return Http::withToken((string) $verbindung->access_token)
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, function (Throwable $ausnahme): bool {
                if ($ausnahme instanceof ConnectionException) {
                    return true;
                }

                return $ausnahme instanceof RequestException && $ausnahme->response->serverError();
            }, throw: false);
    }
}
