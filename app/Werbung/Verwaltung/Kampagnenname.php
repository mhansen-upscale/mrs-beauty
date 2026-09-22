<?php

declare(strict_types=1);

namespace App\Werbung\Verwaltung;

use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Erzeugt den Kampagnennamen -- das Produkt, nicht die Praxis.
 *
 * **Der Name bleibt neutral** (Entscheidung C9). Der Grund steht in
 * `CLAUDE.md` und liegt im eigenen Haus: Kampagnennamen sind Werbe-Metadaten,
 * liegen unverschluesselt und frieren ab WP-32 als attribution_snapshot am
 * Termin ein. "Botox Herbst" setzte damit einen Behandlungsnamen in ein
 * offenes Feld unmittelbar neben einen Kontakt.
 *
 * **Aus der Not wird der Mechanismus.** Weil der Name ohnehin uns gehoert,
 * traegt er ein kurzes, bedeutungsloses Merkmal -- und das ersetzt den
 * Idempotenzschluessel, den Metas Marketing-API nicht hat. Ein Auftrag,
 * dessen Antwort verlorenging, findet seine Kampagne daran wieder, statt eine
 * zweite mit zweitem Budget anzulegen.
 */
final class Kampagnenname
{
    /** Kurz genug fuer Metas Namensgrenze, lang genug gegen Zufallstreffer. */
    private const MERKMAL_LAENGE = 10;

    public static function merkmal(): string
    {
        return Str::lower(Str::random(self::MERKMAL_LAENGE));
    }

    /**
     * Zeitraum, Ziel und Standort -- und sonst nichts.
     */
    public static function fuer(
        string $ziel,
        CarbonImmutable $beginn,
        ?Location $standort,
        string $merkmal,
    ): string {
        $teile = [
            (string) config("mrs.ads.objectives.{$ziel}", $ziel),
            self::monat($beginn),
        ];

        // Der Ort, nicht die Behandlung. Ein Standortname kann alles
        // Moegliche heissen -- aber er ist die Angabe der Praxis ueber sich
        // selbst, keine ueber eine Patientin.
        if ($standort instanceof Location) {
            $teile[] = (string) $standort->city;
        }

        return implode(' · ', array_filter($teile)).' ['.$merkmal.']';
    }

    /**
     * Der Monat auf Deutsch -- ohne locale(), das je nach Carbon-Fassung eine
     * Zeichenkette zurueckgibt statt des Objekts.
     */
    private static function monat(CarbonImmutable $zeitpunkt): string
    {
        $monate = [
            1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
            'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
        ];

        return $monate[$zeitpunkt->month].' '.$zeitpunkt->year;
    }

    /**
     * Findet das Merkmal in einem Namen wieder.
     *
     * Der Weg zurueck: der Abgleich aus WP-26 liest Namen von Meta, und eine
     * Kampagne, die wir angelegt haben, soll sich daran als unsere zu
     * erkennen geben.
     */
    public static function merkmalAus(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return preg_match('/\[([a-z0-9]{'.self::MERKMAL_LAENGE.'})\]$/', trim($name), $treffer) === 1
            ? $treffer[1]
            : null;
    }
}
