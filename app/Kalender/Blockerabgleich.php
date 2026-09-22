<?php

declare(strict_types=1);

namespace App\Kalender;

use App\Models\AppointmentSlot;
use App\Models\ExternalCalendarBlock;
use App\Models\Practitioner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Traegt die externen Blocker in die materialisierte Verfuegbarkeit ein.
 *
 * Die Trennung der drei Spalten aus WP-10 ist hier der ganze Punkt. R3 und
 * Entscheidung B6:
 *
 * | | Quelle | gewinnt |
 * |---|---|---|
 * | Blocker | externer Kalender | `external_block_id` wird gesetzt |
 * | Termin  | System            | `appointment_id` bleibt, kein Blocker |
 *
 * Ein externer Blocker ueber einem bestehenden Termin sagt nichts aus, was
 * das System aufloesen koennte. Er entfernt den Termin nicht und verschiebt
 * ihn nicht -- die Zeile bleibt, wie sie ist. Das Team sieht beides.
 */
final class Blockerabgleich
{
    /**
     * Rechnet die Blocker eines Behandlers im Zeitraum neu.
     *
     * **Ueber alle Verbindungen des Behandlers, nicht nur ueber die eine, die
     * gerade abgeglichen wurde.** Sonst raeumte ein Google-Lauf die Blocker
     * eines zweiten Anbieters weg (WP-15) -- und der Behandler waere zu
     * Zeiten buchbar, zu denen er nicht kann.
     *
     * @return int Zahl der belegten Slot-Zeilen
     */
    public function fuer(Practitioner $behandler, CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return DB::transaction(function () use ($behandler, $von, $bis): int {
            // Erst alles zuruecksetzen. Ein Blocker, der drueben geloescht
            // wurde, hinterlaesst sonst eine Zeile, die auf nichts mehr zeigt
            // -- und eine Zeit, die fuer immer belegt bleibt.
            AppointmentSlot::query()
                ->where('practitioner_id', $behandler->getKey())
                ->whereNotNull('external_block_id')
                ->where('starts_at', '>=', $von)
                ->where('starts_at', '<', $bis)
                ->update(['external_block_id' => null]);

            $blocker = ExternalCalendarBlock::query()
                ->where('practitioner_id', $behandler->getKey())
                ->imZeitraum($von, $bis)
                ->orderBy('starts_at')
                ->get();

            $belegt = 0;

            foreach ($blocker as $block) {
                $belegt += AppointmentSlot::query()
                    ->where('practitioner_id', $behandler->getKey())
                    ->where('starts_at', '>=', $block->starts_at)
                    ->where('starts_at', '<', $block->ends_at)
                    // **frei** heisst: kein Termin, kein gueltiger Hold, kein
                    // anderer Blocker. Ein gehaltener Slot bekommt keinen --
                    // ein Hold ist eine Buchung im Entstehen, und beide
                    // Spalten gleichzeitig zu setzen braeche die Zusage der
                    // Tabelle, dass genau eine von dreien gefuellt ist. Der
                    // Blocker greift, sobald der Hold abgelaufen ist.
                    ->frei()
                    ->update(['external_block_id' => $block->getKey()]);
            }

            return $belegt;
        });
    }

    /**
     * Gibt alle Slots frei, die auf Blocker dieser Verbindung zeigen.
     *
     * Beim Trennen. Ohne das bliebe die Zeit belegt, obwohl niemand mehr
     * weiss, warum.
     *
     * @param  array<int, string>  $blockerIds
     */
    public function gibFrei(array $blockerIds): void
    {
        if ($blockerIds === []) {
            return;
        }

        AppointmentSlot::query()
            ->whereIn('external_block_id', $blockerIds)
            ->update(['external_block_id' => null]);
    }
}
