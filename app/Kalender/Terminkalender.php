<?php

declare(strict_types=1);

namespace App\Kalender;

use App\Jobs\KalenderEventEntfernen;
use App\Jobs\KalenderEventSchreiben;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\CalendarEventLink;
use App\Support\Uuid;
use Illuminate\Support\Collection;

/**
 * Die ausgehende Seite, angebunden an den Terminplaner.
 *
 * Die Klasse stellt Auftraege ein -- sie schreibt nichts selbst. Regel 4:
 * kein schreibender Fremdsystemzugriff im Anfragezyklus. Faellt Google aus,
 * bleibt die Terminverwaltung bedienbar.
 */
final class Terminkalender
{
    public function beiBuchung(Appointment $termin): void
    {
        $this->schreibe($termin);
    }

    public function beiVerschiebung(Appointment $termin): void
    {
        $this->schreibe($termin);
    }

    public function beiAbsage(Appointment $termin): void
    {
        foreach ($this->verknuepfungen($termin) as $verknuepfung) {
            $this->entferne($termin, $verknuepfung);
        }
    }

    private function schreibe(Appointment $termin): void
    {
        $organisation = Uuid::toString($termin->organization_id);

        $verbindungen = CalendarConnection::query()
            ->aktiv()
            ->where('practitioner_id', $termin->practitioner_id)
            ->get();

        foreach ($verbindungen as $verbindung) {
            KalenderEventSchreiben::dispatch(
                (string) $termin->uuid,
                (string) $verbindung->uuid,
                $organisation,
            );
        }

        $this->raeumeAuf($termin, $verbindungen);
    }

    /**
     * Ein Termin, der zu einem anderen Behandler verschoben wurde, steht sonst
     * weiter im Kalender des alten.
     *
     * Der Fall faellt sonst nicht auf: der neue Kalender stimmt, der alte hat
     * einen Eintrag zu viel -- und der blockiert dort Zeit, die frei ist.
     *
     * @param  Collection<int, CalendarConnection>  $behalten
     */
    private function raeumeAuf(Appointment $termin, Collection $behalten): void
    {
        $ids = $behalten->map(fn (CalendarConnection $v): string => (string) $v->getKey())->all();

        $veraltet = $this->verknuepfungen($termin)
            ->reject(fn (CalendarEventLink $l): bool => in_array((string) $l->calendar_connection_id, $ids, true));

        foreach ($veraltet as $verknuepfung) {
            $this->entferne($termin, $verknuepfung);
        }
    }

    /**
     * @return Collection<int, CalendarEventLink>
     */
    private function verknuepfungen(Appointment $termin): Collection
    {
        return CalendarEventLink::query()
            ->where('appointment_id', $termin->getKey())
            ->whereNotNull('external_event_id')
            ->whereNull('removed_at')
            ->with('connection')
            ->get();
    }

    private function entferne(Appointment $termin, CalendarEventLink $verknuepfung): void
    {
        KalenderEventEntfernen::dispatch(
            (string) $termin->uuid,
            (string) $verknuepfung->connection->uuid,
            Uuid::toString($termin->organization_id),
        );
    }
}
