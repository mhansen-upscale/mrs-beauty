<?php

declare(strict_types=1);

namespace App\Datenschutz;

/**
 * Spricht mit clamd ueber INSTREAM.
 *
 * Das Protokoll ist schlicht: `zINSTREAM\0`, dann Bloecke aus vier Byte
 * Laenge und Inhalt, dann ein Block der Laenge null. Die Antwort ist eine
 * Zeile -- `stream: OK` oder `stream: Eicar-Test-Signature FOUND`.
 *
 * **Ueber TCP, nicht ueber einen Unix-Socket**: auf Laravel Cloud laeuft der
 * Pruefer als eigener Dienst und nicht im selben Container.
 */
final class ClamAvVerbindung implements Scanverbindung
{
    public function pruefe(string $inhalt): string
    {
        $host = (string) config('mrs.attachments.scanner.host');
        $port = (int) config('mrs.attachments.scanner.port');
        $zeit = (int) config('mrs.attachments.scanner.timeout_seconds', 10);

        $verbindung = @fsockopen($host, $port, $fehlernummer, $fehlertext, $zeit);

        if ($verbindung === false) {
            throw new ScannerNichtErreichbar('unreachable');
        }

        stream_set_timeout($verbindung, $zeit);

        try {
            fwrite($verbindung, "zINSTREAM\0");

            // In Bloecken, damit auch eine grosse Datei nicht als ein Stueck
            // durch den Speicher muss.
            foreach (str_split($inhalt, 8192) as $block) {
                fwrite($verbindung, pack('N', strlen($block)).$block);
            }

            fwrite($verbindung, pack('N', 0));

            $antwort = (string) fgets($verbindung);
        } finally {
            fclose($verbindung);
        }

        if (trim($antwort) === '') {
            throw new ScannerNichtErreichbar('empty');
        }

        return trim($antwort);
    }
}
