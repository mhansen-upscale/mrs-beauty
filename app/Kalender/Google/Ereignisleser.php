<?php

declare(strict_types=1);

namespace App\Kalender\Google;

use App\Kalender\Eigenmarkierung;
use App\Kalender\Ereignis;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Liest ein Google-Event in ein Ereignis.
 *
 * **Der Originaltitel wird nicht einmal angefasst** (R2): eingehend wird
 * ausschliesslich der Zeitraum uebernommen.
 *
 * Getrennt von der Microsoft-Fassung, und das ist kein Versehen. Google
 * liefert RFC 3339 mit Versatz, Graph eine Ortszeit neben einem Zonennamen --
 * das sind zwei verschiedene Leseweisen, keine zwei Schreibweisen derselben.
 */
final class Ereignisleser
{
    /**
     * @param  array<string, mixed>  $roh
     */
    public static function aus(array $roh, string $kalenderzone, string $organisation): Ereignis
    {
        $privat = data_get($roh, 'extendedProperties.private');
        $abgesagt = data_get($roh, 'status') === 'cancelled';

        // Ganztaegig heisst bei Google: `date` statt `dateTime`, und damit
        // **ohne Zone**. Ausgewertet wird in der Zone des Kalenders -- eine
        // anzunehmen ist der zuverlaessigste Weg zu einem Blocker, der einen
        // Tag zu frueh oder zu spaet liegt.
        $ganztaegig = is_string(data_get($roh, 'start.date'));

        return new Ereignis(
            externeId: (string) data_get($roh, 'id', ''),
            abgesagt: $abgesagt,
            eigen: Eigenmarkierung::istEigen(is_array($privat) ? $privat : [], $organisation),
            frei: data_get($roh, 'transparency') === 'transparent',
            ganztaegig: $ganztaegig,
            beginn: self::zeitpunkt($roh, 'start', $kalenderzone, $ganztaegig),
            ende: self::zeitpunkt($roh, 'end', $kalenderzone, $ganztaegig),
        );
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    private static function zeitpunkt(array $roh, string $feld, string $zone, bool $ganztaegig): ?CarbonImmutable
    {
        if ($ganztaegig) {
            $datum = data_get($roh, $feld.'.date');

            // Das Enddatum ist bei Google **exklusiv** -- genau die Semantik,
            // die ein Blockerende braucht. Keine Korrektur um einen Tag.
            return is_string($datum)
                ? CarbonImmutable::parse($datum, new DateTimeZone($zone))->utc()
                : null;
        }

        $zeit = data_get($roh, $feld.'.dateTime');

        // `dateTime` traegt seinen Versatz selbst mit. Die Umrechnung nach UTC
        // ist damit eindeutig -- auch am Umstellungstag.
        return is_string($zeit) ? CarbonImmutable::parse($zeit)->utc() : null;
    }
}
