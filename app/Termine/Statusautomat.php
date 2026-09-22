<?php

declare(strict_types=1);

namespace App\Termine;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * Welcher Statuswechsel erlaubt ist.
 *
 * Als Tabelle und nicht als if-Kette an vier Aufrufstellen: der Empfang
 * aendert den Status aus der Liste, aus der Detailansicht, ueber die
 * Erinnerung (WP-13) und spaeter ueber den Agenten (WP-24). Vier Stellen sind
 * vier Gelegenheiten, eine Regel zu vergessen.
 *
 *     pending ──▶ confirmed ──▶ attended ⇄ no_show
 *        │   │         │
 *        │   └─────────┴──▶ cancelled        (endgueltig)
 *        └──▶ attended / no_show
 */
final class Statusautomat
{
    /**
     * @return list<AppointmentStatus>
     */
    public static function moeglich(AppointmentStatus $von): array
    {
        return match ($von) {
            AppointmentStatus::Pending => [
                AppointmentStatus::Confirmed,
                AppointmentStatus::Attended,
                AppointmentStatus::NoShow,
                AppointmentStatus::Cancelled,
            ],
            AppointmentStatus::Confirmed => [
                AppointmentStatus::Attended,
                AppointmentStatus::NoShow,
                AppointmentStatus::Cancelled,
            ],

            // Beides wird von Hand gesetzt, und beides wird verwechselt.
            // Deshalb gegeneinander korrigierbar -- aber nicht mehr absagbar:
            // was stattgefunden hat, kann nicht abgesagt werden.
            AppointmentStatus::Attended => [AppointmentStatus::NoShow],
            AppointmentStatus::NoShow => [AppointmentStatus::Attended],

            // Endgueltig. Siehe TerminNichtAenderbar::abgesagt().
            AppointmentStatus::Cancelled => [],
        };
    }

    /**
     * Die Wechsel, die dieser Termin **jetzt** erlaubt.
     *
     * Gebraucht von der Oberflaeche: eine Schaltflaeche anzubieten, die der
     * Server gleich darauf ablehnt, ist keine Bedienoberflaeche, sondern eine
     * Falle.
     *
     * @return list<AppointmentStatus>
     */
    public function moeglichFuer(Appointment $termin, CarbonImmutable $jetzt): array
    {
        return array_values(array_filter(
            self::moeglich($termin->status),
            function (AppointmentStatus $nach) use ($termin, $jetzt): bool {
                try {
                    $this->pruefe($termin, $nach, $jetzt);
                } catch (TerminNichtAenderbar) {
                    return false;
                }

                return true;
            },
        ));
    }

    public function pruefe(Appointment $termin, AppointmentStatus $nach, CarbonImmutable $jetzt): void
    {
        $von = $termin->status;

        if ($von === AppointmentStatus::Cancelled) {
            throw TerminNichtAenderbar::abgesagt();
        }

        if ($von === $nach || ! in_array($nach, self::moeglich($von), true)) {
            throw TerminNichtAenderbar::statuswechsel($von, $nach);
        }

        // "Erschienen" fuer einen Termin in der naechsten Woche ist keine
        // Aussage, sondern ein Vertipper.
        $stattgefunden = in_array($nach, [AppointmentStatus::Attended, AppointmentStatus::NoShow], true);

        if ($stattgefunden && $termin->starts_at > $jetzt) {
            throw TerminNichtAenderbar::nochNichtStattgefunden();
        }
    }
}
