<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AppointmentStatus;
use App\Kalender\Kalenderdienst;
use App\Kalender\Kalenderdienste;
use App\Kalender\ZugangEntzogen;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\CalendarEventLink;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Schreibt einen Termin in den externen Kalender -- einmal.
 *
 * **Die Verknuepfungszeile ist der Idempotenzschluessel** (Entscheidung A13):
 * liegt eine externe Kennung vor, wird aktualisiert statt angelegt. Zwei
 * Laeufe desselben Auftrags erzeugen ein Event, nicht zwei.
 *
 * Und sie traegt R3: ist das Event drueben geloescht worden, antwortet Google
 * mit 404. Der Termin bleibt trotzdem bestehen -- er wird neu geschrieben,
 * nicht abgesagt (Testfall 20 aus docs/fachlogik/verfuegbarkeit.md).
 */
final class KalenderEventSchreiben extends Kalenderauftrag implements ShouldQueue
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
            $termin = Appointment::query()
                ->whereUuid($this->termin)
                ->with('location')
                ->first();

            if (! $termin instanceof Appointment || $termin->status === AppointmentStatus::Cancelled) {
                return;
            }

            $verknuepfung = CalendarEventLink::query()->firstOrNew([
                'appointment_id' => $termin->getKey(),
                'calendar_connection_id' => $verbindung->getKey(),
            ]);

            try {
                $verknuepfung->external_event_id = $this->schreibe(
                    $dienste->zu($verbindung), $verbindung, $verknuepfung, $termin
                );
            } catch (ZugangEntzogen) {
                $verbindung->meldeAusfall('access_revoked');

                return;
            }

            $verknuepfung->removed_at = null;
            $verknuepfung->synced_at = CarbonImmutable::now();
            $verknuepfung->save();
        });
    }

    private function schreibe(
        Kalenderdienst $dienst,
        CalendarConnection $verbindung,
        CalendarEventLink $verknuepfung,
        Appointment $termin,
    ): string {
        $kennung = $verknuepfung->external_event_id;

        if ($verknuepfung->stehtDraussen() && is_string($kennung)) {
            if ($dienst->aktualisiere($verbindung, $kennung, $termin)) {
                return $kennung;
            }

            // Extern geloescht. R3: der Termin bleibt, das Event kommt wieder.
        }

        return $dienst->lege($verbindung, $termin);
    }
}
