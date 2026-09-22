<?php

declare(strict_types=1);

namespace App\Kanaele\Email;

use App\Kanaele\Eingangsnachricht;
use App\Kanaele\Kanaleingang;

/**
 * Der Leser des E-Mail-Kanals.
 *
 * **Und die Gegenprobe auf WP-19.** Die Strecke -- Empfang, Rohereignis,
 * Deduplizierung, Konversation, Versandwarteschlange -- wurde fuer Meta
 * gebaut. Hier laeuft ein Kanal hindurch, der keine Signatur von Meta hat,
 * kein Service-Fenster kennt und nichts kostet. Was daran nicht passt, ist
 * ein Befund ueber die Schnittstelle und nicht ueber die E-Mail.
 *
 * Der Eintrag traegt genau ein Feld: `raw`, die Mail, wie sie ankam. Das
 * Zerlegen macht Mailleser -- **kein** Anbieterformat, sondern RFC 5322.
 * Jeder Eingangsdienst kann das liefern, und eine spaetere Abholung ueber
 * IMAP braeuchte dieselbe Zeile.
 *
 * Rueckmeldungen gibt es nicht: eine Mail meldet nicht, dass sie gelesen
 * wurde. Ein Unzustellbarkeitsbericht kommt als neue Mail und ist eine.
 * Deshalb setzt dieser Kanal Rueckmeldungsleser nicht um.
 */
final class EmailEingang implements Kanaleingang
{
    public function __construct(private readonly Mailleser $leser) {}

    /**
     * @param  array<string, mixed>  $eintrag
     * @return list<Eingangsnachricht>
     */
    public function lies(array $eintrag): array
    {
        $roh = $eintrag['raw'] ?? null;

        if (! is_string($roh) || $roh === '') {
            return [];
        }

        $nachricht = $this->leser->lies($roh);

        return $nachricht instanceof Eingangsnachricht ? [$nachricht] : [];
    }
}
