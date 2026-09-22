<?php

declare(strict_types=1);

namespace App\Kalender\Google;

use App\Enums\CalendarPrivacyMode;
use App\Kalender\Eigenmarkierung;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Support\Uuid;

/**
 * Der Inhalt eines ausgehenden Kalendereintrags bei Google.
 *
 * **Neutraler Titel, kein Kontaktname, keine Behandlung** (Regel 3, R2). Der
 * Kalender eines Arztes liegt oft auf dem Privathandy und ist manchmal mit
 * dem Team geteilt. Ein Event "Frau Berger -- Lippenaufbau, 14:00" auf einem
 * Sperrbildschirm ist ein Gesundheitsdatum in der Oeffentlichkeit.
 *
 * Belegt wird `blocked_from` bis `blocked_until`, also **mit** Ruestzeit: der
 * Behandler kann in dieser Zeit nichts anderes tun. Dem Kontakt angezeigt
 * wird `starts_at` -- das ist eine andere Frage und steht in der Mail.
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

        $ereignis = [
            'summary' => (string) config('mrs.calendar.outgoing_event_title', 'Beratung'),
            'start' => [
                'dateTime' => $standort->ortszeit($termin->blocked_from)->toRfc3339String(),
                'timeZone' => $zone,
            ],
            'end' => [
                'dateTime' => $standort->ortszeit($termin->blocked_until)->toRfc3339String(),
                'timeZone' => $zone,
            ],
            'location' => self::anschrift($termin),

            // Belegt, nicht "frei". Sonst blockiert der Eintrag nichts und
            // der Kalender des Behandlers nimmt daneben weitere Termine an.
            'transparency' => 'opaque',

            // R1/B5. Ohne diese vier Zeilen erzeugt der naechste Rueckabgleich
            // aus diesem Event einen Blocker ueber dem eigenen Termin.
            'extendedProperties' => [
                'private' => Eigenmarkierung::fuer($termin, Uuid::toString($termin->organization_id)),
            ],
        ];

        // Entscheidung B4 kennt einen zweiten Modus. Er fuegt **keinen Inhalt
        // hinzu, sondern einen Weg zum Inhalt**: wer wissen will, wer kommt,
        // meldet sich an. Regel 3 bleibt damit unberuehrt.
        if ($verbindung->privacy_mode === CalendarPrivacyMode::Details) {
            $ereignis['description'] = route('appointments.index', ['termin' => $termin->uuid]);
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
