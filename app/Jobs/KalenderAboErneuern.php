<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Kalender\Abonnements;
use App\Kalender\KalenderNichtErreichbar;
use App\Kalender\ZugangEntzogen;
use App\Models\CalendarConnection;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Erneuert ein Watch-Abonnement.
 *
 * R4: ein Abonnement laeuft nach hoechstens 30 Tagen ab, und nichts daran
 * meldet sich. Wer es erst kurz vor Ablauf erneuert, hat nach einem einzigen
 * ausgefallenen Joblauf einen toten Sync -- und damit Termine ueber belegten
 * Zeiten.
 */
final class KalenderAboErneuern extends Kalenderauftrag implements ShouldQueue
{
    public function handle(Abonnements $abonnements): void
    {
        $this->mitVerbindung(function (CalendarConnection $verbindung) use ($abonnements): void {
            try {
                $abonnements->erneuere($verbindung);
            } catch (ZugangEntzogen) {
                $verbindung->meldeAusfall('access_revoked');
            } catch (KalenderNichtErreichbar) {
                // Anders als ein entzogener Zugang ist das voruebergehend.
                // Der Job wandert in die Wiederholung, die Verbindung bleibt
                // aktiv -- und der naechste Planerlauf versucht es erneut.
                $verbindung->last_error = 'renew_failed';
                $verbindung->save();
            }
        });
    }
}
