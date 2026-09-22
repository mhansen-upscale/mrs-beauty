<?php

declare(strict_types=1);

namespace App\Kalender\Microsoft;

use App\Kalender\Eigenmarkierung;
use App\Kalender\Ereignis;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * Liest ein Graph-Event in ein Ereignis.
 *
 * **Der Originaltitel wird nicht einmal angefasst** (R2).
 *
 * Der teuerste Unterschied zu Google steckt in der Zeit: Graph liefert
 * `{"dateTime": "2027-01-13T09:00:00.0000000", "timeZone": "UTC"}` -- die
 * Zeichenkette allein ist **mehrdeutig**. Google lieferte RFC 3339 mit
 * Versatz, dort war die Umrechnung eindeutig. Wer das Feld `timeZone`
 * uebersieht, bekommt Blocker, die still um Stunden danebenliegen.
 */
final class Ereignisleser
{
    /**
     * @param  array<string, mixed>  $roh
     * @param  string  $kalenderzone  Rueckfall, wenn das Event keine brauchbare Zone nennt
     */
    public static function aus(array $roh, string $kalenderzone, string $organisation): Ereignis
    {
        // Im Delta kommt eine Loeschung als Eintrag mit '@removed' -- ohne
        // Zeiten, ohne alles. Eine abgesagte Besprechung kommt dagegen
        // vollstaendig, mit isCancelled.
        $entfernt = array_key_exists('@removed', $roh);

        return new Ereignis(
            externeId: (string) data_get($roh, 'id', ''),
            abgesagt: $entfernt || data_get($roh, 'isCancelled') === true,
            eigen: self::istEigen($roh, $organisation),
            frei: data_get($roh, 'showAs') === 'free',
            ganztaegig: data_get($roh, 'isAllDay') === true,
            beginn: self::zeitpunkt($roh, 'start', $kalenderzone),
            ende: self::zeitpunkt($roh, 'end', $kalenderzone),
        );
    }

    /**
     * R1 ueber eine Open Extension.
     *
     * **Sie kommt nicht von allein mit.** Graph liefert Erweiterungen nur auf
     * ausdrueckliche Anforderung, und die Delta-Abfrage kennt kein $expand.
     * Diese Pruefung ist deshalb nur die eine Haelfte von R1; die andere
     * steht in Rueckabgleich und vergleicht gegen die Kennungen, die wir
     * selbst geschrieben haben.
     *
     * @param  array<string, mixed>  $roh
     */
    private static function istEigen(array $roh, string $organisation): bool
    {
        foreach ((array) data_get($roh, 'extensions', []) as $erweiterung) {
            if (! is_array($erweiterung)) {
                continue;
            }

            if (data_get($erweiterung, 'extensionName') !== Eigenmarkierung::schluessel()) {
                continue;
            }

            if (Eigenmarkierung::istEigen($erweiterung, $organisation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `dateTime` **plus** `timeZone` -- nie das eine ohne das andere.
     *
     * Graph nennt die Zone gelegentlich in Windows-Schreibweise ("W. Europe
     * Standard Time"), die PHP nicht kennt. Dann gilt die Zone des Kalenders:
     * lieber der bekannte Standort als eine stillschweigende Annahme auf UTC.
     *
     * @param  array<string, mixed>  $roh
     */
    private static function zeitpunkt(array $roh, string $feld, string $kalenderzone): ?CarbonImmutable
    {
        $wert = data_get($roh, $feld.'.dateTime');

        if (! is_string($wert) || $wert === '') {
            return null;
        }

        $name = data_get($roh, $feld.'.timeZone');

        return CarbonImmutable::parse(
            $wert,
            self::zone(is_string($name) ? $name : '', $kalenderzone)
        )->utc();
    }

    private static function zone(string $name, string $rueckfall): DateTimeZone
    {
        foreach ([$name, $rueckfall] as $kandidat) {
            if ($kandidat === '') {
                continue;
            }

            try {
                return new DateTimeZone($kandidat);
            } catch (Throwable) {
                // Naechster Kandidat.
            }
        }

        return new DateTimeZone('UTC');
    }
}
