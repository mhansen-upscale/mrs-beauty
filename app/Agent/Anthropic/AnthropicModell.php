<?php

declare(strict_types=1);

namespace App\Agent\Anthropic;

use App\Agent\Anfrage;
use App\Agent\Antwort;
use App\Agent\ModellNichtErreichbar;
use App\Agent\Sprachmodell;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Die Anbindung an die Messages-API von Anthropic.
 *
 * **Die Version steht in der Konfiguration**, nicht im Code -- dieselbe
 * Ueberlegung wie bei der Graph-API (docs/integrationen/meta.md): ein
 * Anbieter setzt Versionen ab, und das faellt sonst erst im Betrieb auf.
 *
 * **Anweisung und Daten bleiben getrennt.** Die Anweisung geht als `system`,
 * die Nachricht der Person als Inhalt einer Benutzernachricht in einem
 * abgegrenzten Block (Regel 5). Wer beides zusammenfuegen will, muss dafuer
 * App\Agent\Anfrage aendern.
 */
final class AnthropicModell implements Sprachmodell
{
    public function frage(Anfrage $anfrage): Antwort
    {
        if (! $this->angebunden()) {
            throw new ModellNichtErreichbar('no_key');
        }

        try {
            $antwort = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => (string) config('services.anthropic.version'),
            ])
                ->acceptJson()
                ->timeout(30)
                // Nur Ausfaelle werden wiederholt. Eine Ablehnung wird beim
                // zweiten Versuch nicht angenommen -- dieselbe Ueberlegung
                // wie bei den Kanaelen (WP-19).
                ->retry(2, 500, function (Throwable $ausnahme): bool {
                    if ($ausnahme instanceof ConnectionException) {
                        return true;
                    }

                    return $ausnahme instanceof RequestException
                        && ($ausnahme->response->serverError() || $ausnahme->response->status() === 429);
                }, throw: false)
                ->post(rtrim((string) config('services.anthropic.url'), '/').'/v1/messages', [
                    'model' => $anfrage->modell,
                    'max_tokens' => $anfrage->hoechstenTokens,

                    // **Die Anweisung, und nur sie.** Was von aussen kommt,
                    // steht nicht hier.
                    'system' => $anfrage->anweisung,

                    'messages' => [[
                        'role' => 'user',
                        'content' => $anfrage->datenblock(),
                    ]],

                    // Eine Einordnung soll bei derselben Nachricht dasselbe
                    // ergeben. Kreativitaet ist hier keine Tugend.
                    'temperature' => 0,
                ]);
        } catch (Throwable) {
            throw new ModellNichtErreichbar('unreachable');
        }

        if ($antwort->failed()) {
            throw new ModellNichtErreichbar($antwort->status() === 429 ? 'rate_limit' : 'rejected');
        }

        $inhalt = data_get($antwort->json(), 'content.0.text');

        if (! is_string($inhalt) || $inhalt === '') {
            throw new ModellNichtErreichbar('empty');
        }

        return new Antwort(
            inhalt: $inhalt,
            modell: (string) (data_get($antwort->json(), 'model') ?? $anfrage->modell),
            eingabeTokens: (int) (data_get($antwort->json(), 'usage.input_tokens') ?? 0),
            ausgabeTokens: (int) (data_get($antwort->json(), 'usage.output_tokens') ?? 0),
        );
    }

    public function angebunden(): bool
    {
        return is_string(config('services.anthropic.key')) && config('services.anthropic.key') !== '';
    }
}
