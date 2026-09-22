<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Kalender\Kalenderdienste;
use App\Kalender\ZugangEntzogen;
use App\Models\CalendarConnection;
use App\Models\CalendarEventLink;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Nimmt einen abgesagten Termin aus dem externen Kalender.
 *
 * Ein Event, das drueben schon weg ist, ist ein Erfolg -- der Anbieter
 * antwortet mit 404, und das ist genau der gewuenschte Endzustand.
 */
final class KalenderEventEntfernen extends Kalenderauftrag implements ShouldQueue
{
    public function __construct(
        private readonly string $termin,
        string $verbindung,
        string $organisation,
    ) {
        parent::__construct($verbindung, $organisation);
    }

    public function handle(Kalenderdienste $dienste): void
    {
        $this->mitVerbindung(function (CalendarConnection $verbindung) use ($dienste): void {
            $verknuepfung = CalendarEventLink::query()
                ->where('calendar_connection_id', $verbindung->getKey())
                ->whereHas('appointment', fn ($abfrage) => $abfrage->whereUuid($this->termin))
                ->first();

            if (! $verknuepfung instanceof CalendarEventLink || ! $verknuepfung->stehtDraussen()) {
                return;
            }

            try {
                $dienste->zu($verbindung)->entferne($verbindung, (string) $verknuepfung->external_event_id);
            } catch (ZugangEntzogen) {
                $verbindung->meldeAusfall('access_revoked');

                return;
            }

            $verknuepfung->removed_at = CarbonImmutable::now();
            $verknuepfung->save();
        });
    }
}
