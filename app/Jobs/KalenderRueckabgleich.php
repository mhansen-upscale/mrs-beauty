<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Kalender\Rueckabgleich;
use App\Models\CalendarConnection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Liest einen externen Kalender und fuehrt die Blocker nach.
 *
 * Eingestellt vom Webhook (A14: sofort quittieren, asynchron verarbeiten),
 * vom naechtlichen Vollabgleich und nach dem Verbinden.
 *
 * `ShouldBeUnique`: eine Praxis, die zwanzig Termine hintereinander verschiebt,
 * erzeugt zwanzig Zustellungen. Der zweite Lauf haette nichts zu tun, was der
 * erste nicht schon getan haette.
 */
final class KalenderRueckabgleich extends Kalenderauftrag implements ShouldBeUnique, ShouldQueue
{
    /** Ein haengengebliebener Lauf soll den Sync nicht auf Dauer blockieren. */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'kalender-rueckabgleich:'.$this->verbindung;
    }

    public function handle(Rueckabgleich $rueckabgleich): void
    {
        $this->mitVerbindung(function (CalendarConnection $verbindung) use ($rueckabgleich): void {
            $rueckabgleich->fuer($verbindung);
        });
    }
}
