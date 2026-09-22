<?php

declare(strict_types=1);

namespace App\Kalender\Microsoft;

use App\Enums\CalendarPrivacyMode;
use App\Kalender\Eigenmarkierung;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Support\Uuid;

/**
 * Der Inhalt eines ausgehenden Kalendereintrags bei Graph.
 *
 * Inhaltlich dasselbe wie bei Google -- **neutraler Titel, kein Kontaktname,
 * keine Behandlung** (Regel 3, R2) -- nur anders geschrieben. Belegt wird
 * `blocked_from` bis `blocked_until`, also mit Ruestzeit.
 *
 * Zwei Eigenheiten: die Zeit traegt **keinen** Versatz, sie steht neben der
 * Zone. Und die Eigenmarkierung ist eine Open Extension, kein Feld.
 */
final class Ausgangsereignis
{
    /**
     * @return array<string, mixed>
     */
    public static function fuer(Appointment $termin, CalendarConnection $verbindung): array
    {
        $standort = $termin->location;
        $zone = $standort->timezone;
        $organisation = Uuid::toString($termin->organization_id);

        $ereignis = [
            'subject' => (string) config('mrs.calendar.outgoing_event_title', 'Beratung'),

            // Ortszeit **ohne** Versatz plus Zonenname -- so erwartet Graph es,
            // und so ist es eindeutig.
            'start' => [
                'dateTime' => $standort->ortszeit($termin->blocked_from)->format('Y-m-d\TH:i:s'),
                'timeZone' => $zone,
            ],
            'end' => [
                'dateTime' => $standort->ortszeit($termin->blocked_until)->format('Y-m-d\TH:i:s'),
                'timeZone' => $zone,
            ],

            'location' => ['displayName' => self::anschrift($termin)],

            // Belegt, nicht "frei" -- sonst nimmt der Kalender daneben weitere
            // Termine an.
            'showAs' => 'busy',

            // R1/B5 als Open Extension. Ohne sie erzeugt der naechste
            // Rueckabgleich aus diesem Event einen Blocker ueber dem eigenen
            // Termin.
            'extensions' => [array_merge(
                [
                    '@odata.type' => 'microsoft.graph.openTypeExtension',
                    'extensionName' => Eigenmarkierung::schluessel(),
                ],
                Eigenmarkierung::fuer($termin, $organisation),
            )],
        ];

        // Entscheidung B4: ein Weg zum Inhalt, kein Inhalt.
        if ($verbindung->privacy_mode === CalendarPrivacyMode::Details) {
            $ereignis['body'] = [
                'contentType' => 'text',
                'content' => route('appointments.index', ['termin' => $termin->uuid]),
            ];
        }

        return $ereignis;
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
}
