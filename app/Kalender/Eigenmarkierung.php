<?php

declare(strict_types=1);

namespace App\Kalender;

use App\Models\Appointment;

/**
 * Regel R1 und Entscheidung B5: jedes ausgehende Event traegt eine Markierung.
 *
 * Ohne sie schreibt das System seinen eigenen Termin als externen Blocker
 * zurueck, blockiert damit den eigenen Slot und erzeugt eine Endlosschleife.
 * docs/integrationen/kalender.md nennt das "den haeufigsten Fehler bei
 * Kalenderintegrationen".
 *
 * **Die Marke traegt die Organisation, nicht nur das Produkt.** Ein Behandler
 * kann fuer zwei Praxen arbeiten und denselben Kalender verbinden. Das Event
 * der einen Praxis ist fuer die andere dann echte belegte Zeit -- wer nur auf
 * den Schluessel prueft, uebersieht genau diesen Fall und bucht doppelt.
 */
final class Eigenmarkierung
{
    /**
     * @return array<string, string>
     */
    public static function fuer(Appointment $termin, string $organisation): array
    {
        $schluessel = self::schluessel();

        return [
            $schluessel => $organisation,
            $schluessel.'_appointment' => (string) $termin->uuid,
        ];
    }

    /**
     * @param  array<string, mixed>  $privat  extendedProperties.private
     */
    public static function istEigen(array $privat, string $organisation): bool
    {
        return ($privat[self::schluessel()] ?? null) === $organisation;
    }

    public static function schluessel(): string
    {
        return (string) config('mrs.calendar.marker_key', 'mrs_beauty');
    }
}
