<?php

declare(strict_types=1);

namespace Tests\Feature\Kanaele;

use App\Kanaele\Eingangsnachricht;
use App\Kanaele\Kanaleingang;
use App\Kanaele\Kanalfehler;
use App\Kanaele\Kanalversand;
use App\Kanaele\Versandergebnis;
use App\Models\ChannelConnection;
use App\Models\Message;
use App\Support\Fehlereinordnung;
use Carbon\CarbonImmutable;

/**
 * Ein Kanal, wie WP-20 ihn bauen wird -- nur klein.
 *
 * WP-19 baut die Strecke, nicht die Kanaele. Um sie zu pruefen, braucht es
 * einen: er liest eine Nutzlast in der Form, die Messenger liefert, und sein
 * Versand laesst sich in die Fehlerklassen aus docs/integrationen/meta.md
 * versetzen.
 */
final class Testkanal implements Kanaleingang, Kanalversand
{
    /** @var list<Message> */
    public array $gesendet = [];

    /** Die naechste Antwort des Anbieters -- null heisst Erfolg. */
    public ?Fehlereinordnung $fehler = null;

    public Versandergebnis $ergebnis;

    public function __construct()
    {
        $this->ergebnis = new Versandergebnis('extern-1');
    }

    /**
     * Die Form, die Messenger liefert: `messaging[]` mit `sender.id` und
     * `message.mid`.
     *
     * @param  array<string, mixed>  $eintrag
     * @return list<Eingangsnachricht>
     */
    public function lies(array $eintrag): array
    {
        $nachrichten = [];

        foreach ((array) data_get($eintrag, 'messaging', []) as $ereignis) {
            $kennung = data_get($ereignis, 'message.mid');
            $absender = data_get($ereignis, 'sender.id');

            if (! is_string($kennung) || ! is_string($absender)) {
                continue;
            }

            $zeit = data_get($ereignis, 'timestamp');

            $nachrichten[] = new Eingangsnachricht(
                externeId: $kennung,
                absender: $absender,
                inhalt: is_string(data_get($ereignis, 'message.text')) ? (string) data_get($ereignis, 'message.text') : null,
                zeitpunkt: is_numeric($zeit) ? CarbonImmutable::createFromTimestampMs((int) $zeit, 'UTC') : null,
            );
        }

        return $nachrichten;
    }

    public function sende(ChannelConnection $verbindung, Message $nachricht): Versandergebnis
    {
        if ($this->fehler instanceof Fehlereinordnung) {
            throw new Kanalfehler($this->fehler);
        }

        $this->gesendet[] = $nachricht;

        return $this->ergebnis;
    }

    /**
     * Eine Zustellung, wie Meta sie schickt.
     *
     * @return array<string, mixed>
     */
    public static function zustellung(string $seite, string $absender, string $kennung, string $text = 'Hallo'): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $seite,
                'time' => 1_800_000_000_000,
                'messaging' => [[
                    'sender' => ['id' => $absender],
                    'recipient' => ['id' => $seite],
                    'timestamp' => 1_800_000_000_000,
                    'message' => ['mid' => $kennung, 'text' => $text],
                ]],
            ]],
        ];
    }
}
