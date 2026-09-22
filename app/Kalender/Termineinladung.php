<?php

declare(strict_types=1);

namespace App\Kalender;

use App\Enums\NotificationKind;
use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * Die Kalenderdatei im Anhang einer Terminnachricht.
 *
 * Aus WP-13 hierher verschoben: dort war die Kalenderlogik noch nicht da.
 *
 * **Der Titel nennt keine Behandlung.** Ein Kalendereintrag taucht als
 * Erinnerung auf einem Sperrbildschirm auf -- dieselbe Ueberlegung wie bei
 * der Betreffzeile (WP-13) und beim ausgehenden Event (R2). Was der Termin
 * ist, steht in der Beschreibung; dorthin sieht nur, wer den Eintrag oeffnet.
 *
 * Angezeigt wird `starts_at` bis `ends_at`, nicht die belegte Strecke: der
 * Kontakt soll zur Terminzeit kommen und nicht zur Ruestzeit.
 */
final class Termineinladung
{
    /**
     * Drei von fuenf Anlaessen tragen eine Datei.
     *
     * Die Eingangsbestaetigung nicht -- ein angefragter Termin gehoert noch
     * nicht in einen Kalender. Die Erinnerung nicht -- wer eine bekommt, hat
     * den Eintrag laengst.
     */
    public static function gehoertDazu(NotificationKind $art): bool
    {
        return in_array($art, [
            NotificationKind::Confirmation,
            NotificationKind::Rescheduled,
            NotificationKind::Cancellation,
        ], true);
    }

    public static function methode(NotificationKind $art): string
    {
        return $art === NotificationKind::Cancellation ? 'CANCEL' : 'REQUEST';
    }

    public static function fuer(Appointment $termin, string $praxis, NotificationKind $art): string
    {
        $absage = $art === NotificationKind::Cancellation;

        $zeilen = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Mrs. Beauty//Termin//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:'.self::methode($art),
            'BEGIN:VEVENT',
            'UID:'.self::kennung($termin),
            'SEQUENCE:'.self::folge($termin),
            'DTSTAMP:'.self::zeit(CarbonImmutable::now()),
            'DTSTART:'.self::zeit($termin->starts_at),
            'DTEND:'.self::zeit($termin->ends_at),
            'SUMMARY:'.self::text('Termin bei '.$praxis),
            'DESCRIPTION:'.self::text(
                $termin->appointmentType->name.' bei '.$termin->practitioner->name()
            ),
            'LOCATION:'.self::text(self::anschrift($termin)),
            'STATUS:'.($absage ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::falte(...), $zeilen))."\r\n";
    }

    /**
     * Dieselbe Kennung ueber alle Nachrichten zu einem Termin.
     *
     * Nur so ersetzt die Verschiebung den Eintrag, statt einen zweiten
     * anzulegen -- und nur so entfernt die Absage ihn wieder.
     */
    private static function kennung(Appointment $termin): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $termin->uuid.'@'.(is_string($host) && $host !== '' ? $host : 'mrs.beauty');
    }

    /**
     * Die Folgenummer muss wachsen, sonst ignorieren Kalenderprogramme die
     * Aktualisierung. Sekunden seit dem Anlegen des Termins wachsen
     * verlaesslich und bleiben klein genug, um lesbar zu sein.
     */
    private static function folge(Appointment $termin): int
    {
        $angelegt = $termin->created_at;
        $geaendert = $termin->updated_at;

        if ($angelegt === null || $geaendert === null) {
            return 0;
        }

        return (int) $angelegt->diffInSeconds($geaendert, absolute: true);
    }

    private static function anschrift(Appointment $termin): string
    {
        $standort = $termin->location;

        $teile = array_filter([
            $standort->street,
            trim(($standort->postal_code ?? '').' '.($standort->city ?? '')),
        ], fn (?string $teil): bool => $teil !== null && $teil !== '');

        return $teile === [] ? $standort->name : $standort->name.', '.implode(', ', $teile);
    }

    private static function zeit(CarbonImmutable $zeitpunkt): string
    {
        return $zeitpunkt->utc()->format('Ymd\THis\Z');
    }

    /** RFC 5545: Komma, Semikolon, Backslash und Zeilenumbrueche sind gesetzt. */
    private static function text(string $wert): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\\,', '\\n', '\\n'],
            $wert
        );
    }

    /**
     * Zeilen laenger als 75 Oktette werden umbrochen, Folgezeilen beginnen mit
     * einem Leerzeichen. Ohne das schneiden manche Programme still ab -- und
     * dann fehlt die Anschrift, nicht der Umbruch.
     */
    private static function falte(string $zeile): string
    {
        if (strlen($zeile) <= 75) {
            return $zeile;
        }

        $teile = [];
        $rest = $zeile;
        $grenze = 75;

        while (strlen($rest) > $grenze) {
            // Nicht mitten in ein Mehrbyte-Zeichen schneiden.
            $schnitt = $grenze;

            while ($schnitt > 1 && (ord($rest[$schnitt]) & 0xC0) === 0x80) {
                $schnitt--;
            }

            $teile[] = substr($rest, 0, $schnitt);
            $rest = substr($rest, $schnitt);
            $grenze = 74;
        }

        $teile[] = $rest;

        return implode("\r\n ", $teile);
    }
}
