<?php

declare(strict_types=1);

namespace App\Werbung\Verwaltung;

use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Was angelegt werden soll -- und was dabei nicht erlaubt ist.
 *
 * **Die Oberflaeche erzwingt die Anforderungen, statt die API-Ablehnung
 * anzuzeigen** (docs/produkt.md). Eine Kampagne, die beim Speichern eine
 * englische Meta-Fehlermeldung zurueckgibt, laesst das Produkt kaputt
 * aussehen -- und die Praxis weiss danach immer noch nicht, was sie aendern
 * soll.
 *
 * **Die Regeln stehen an einer Stelle.** `regeln()` speist das Formular, und
 * dieser Traeger wird erst danach gebaut. Zwei Orte fuer dieselbe Vorgabe
 * waeren zwei Vorgaben, sobald jemand eine davon aendert.
 *
 * **Interessen fehlen mit Absicht.** "Botox" als Interesse auszuwaehlen waere
 * eine Behandlungsbezeichnung Richtung Meta -- Regel 2, in einem Feld, an das
 * niemand denkt. Zielgruppe ist Umkreis, Alter, Geschlecht.
 */
final class Kampagnenplan
{
    public function __construct(
        public readonly string $ziel,
        public readonly int $tagesbudgetMinor,
        public readonly CarbonImmutable $beginn,
        public readonly ?CarbonImmutable $ende,
        public readonly string $standort,
        public readonly int $umkreisKm,
        public readonly int $altervon,
        public readonly int $alterbis,
        public readonly ?string $geschlecht,
    ) {}

    /**
     * Die Vorgaben als Validierungsregeln -- die einzige Fassung davon.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function regeln(?AdAccount $konto): array
    {
        $mindestalter = (int) config('mrs.ads.min_age');
        $hoechstalter = (int) config('mrs.ads.max_age');

        return [
            'ziel' => ['required', Rule::in(array_keys((array) config('mrs.ads.objectives')))],

            // Unterhalb der Untergrenze liefert Meta nicht aus, und das Geld
            // liegt trotzdem fest.
            'tagesbudget' => ['required', 'integer', 'min:'.self::mindestbudget($konto)],

            'beginn' => ['required', 'date'],
            'ende' => ['nullable', 'date', 'after:beginn'],

            'standort' => ['required', 'string'],
            'umkreis' => [
                'required', 'integer',
                'min:'.(int) config('mrs.ads.radius_km.min'),
                'max:'.(int) config('mrs.ads.radius_km.max'),
            ],

            // **Keine Bewerbung aesthetischer Eingriffe an Minderjaehrige.**
            // Das Feld laesst gar nichts anderes zu.
            'altervon' => ['required', 'integer', 'min:'.$mindestalter, 'max:'.$hoechstalter],
            'alterbis' => ['required', 'integer', 'min:'.$mindestalter, 'max:'.$hoechstalter, 'gte:altervon'],

            'geschlecht' => ['nullable', Rule::in(['weiblich', 'maennlich'])],
        ];
    }

    /**
     * Metas Untergrenzen haengen an der Waehrung. Ein fester Cent-Betrag
     * waere fuer ein Konto in Franken falsch.
     */
    public static function mindestbudget(?AdAccount $konto): int
    {
        $waehrung = $konto instanceof AdAccount && is_string($konto->currency) ? $konto->currency : 'EUR';
        $grenzen = (array) config('mrs.ads.min_daily_budget');

        return (int) ($grenzen[$waehrung] ?? $grenzen['EUR'] ?? 100);
    }

    /**
     * Metas Zielgruppen-Nutzlast. Umkreis, Alter, Geschlecht -- mehr nicht.
     *
     * @param  array{lat: float, lng: float}  $ort
     * @return array<string, mixed>
     */
    public function zielgruppe(array $ort): array
    {
        $daten = [
            'geo_locations' => [
                'custom_locations' => [[
                    'latitude' => $ort['lat'],
                    'longitude' => $ort['lng'],
                    'radius' => $this->umkreisKm,
                    'distance_unit' => 'kilometer',
                ]],
            ],
            'age_min' => $this->altervon,
            'age_max' => $this->alterbis,
        ];

        if ($this->geschlecht !== null) {
            $daten['genders'] = [$this->geschlecht === 'weiblich' ? 2 : 1];
        }

        return $daten;
    }
}
