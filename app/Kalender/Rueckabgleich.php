<?php

declare(strict_types=1);

namespace App\Kalender;

use App\Enums\CalendarConnectionStatus;
use App\Models\CalendarConnection;
use App\Models\CalendarEventLink;
use App\Models\ExternalCalendarBlock;
use Carbon\CarbonImmutable;

/**
 * Liest den externen Kalender und fuehrt die Blocker nach.
 *
 * Die Reihenfolge der Pruefungen ist die eigentliche Aussage dieser Klasse:
 * **zuerst die Eigenmarkierung**, dann alles andere. Ohne R1 schreibt das
 * System seinen eigenen Termin als externen Blocker zurueck, blockiert damit
 * den eigenen Slot und erzeugt eine Endlosschleife.
 */
final class Rueckabgleich
{
    public function __construct(
        private readonly Kalenderdienste $dienste,
        private readonly Blockerabgleich $blocker,
    ) {}

    public function fuer(CalendarConnection $verbindung, ?CarbonImmutable $jetzt = null): Abgleichergebnis
    {
        $jetzt ??= CarbonImmutable::now();

        $von = $jetzt->startOfDay();
        $bis = $jetzt->addDays((int) config('mrs.booking.horizon_days', 90))->endOfDay();

        $dienst = $this->dienste->zu($verbindung);

        try {
            [$seite, $voll] = $this->hole($dienst, $verbindung, $von, $bis);
        } catch (ZugangEntzogen) {
            // R4: der Ausfall wird sichtbar, nicht verschluckt. Keine
            // Wiederholung -- ein entzogener Zugang kommt nicht von selbst
            // wieder.
            $verbindung->meldeAusfall('access_revoked');

            return Abgleichergebnis::unterbrochen();
        }

        if ($voll) {
            // Ein Vollabgleich ersetzt den Bestand. Was drueben nicht mehr
            // steht, kommt sonst nie wieder weg -- das Delta haette es
            // gemeldet, der Vollabgleich meldet nur, was es gibt.
            ExternalCalendarBlock::query()
                ->where('calendar_connection_id', $verbindung->getKey())
                ->delete();
        }

        $uebernommen = 0;
        $entfernt = 0;
        $uebersprungen = 0;

        // **Die zweite Haelfte von R1.** Die Eigenmarkierung am Event ist die
        // erste; sie kommt aber nicht bei jedem Anbieter auf jedem Weg mit --
        // die Delta-Abfrage von Graph traegt keine Erweiterungen. Was wir
        // selbst geschrieben haben, wissen wir ohnehin: es steht in
        // calendar_event_links. Zwei Wege zum selben Schutz, und der
        // teurere Fehler (der eigene Termin als externer Blocker) ist damit
        // nicht von einer einzigen Abfrage abhaengig.
        $eigene = $this->eigeneKennungen($verbindung);

        foreach ($seite->ereignisse as $ereignis) {
            if ($ereignis->externeId === '') {
                continue;
            }

            if (in_array($ereignis->externeId, $eigene, true)) {
                $ereignis = $ereignis->alsEigenes();
            }

            // **R1, und zwar als erste Pruefung.** Ein eigenmarkiertes Event
            // ist unser eigener Termin. Er erzeugt keinen Blocker -- und ein
            // vorhandener wird abgeraeumt, falls die Markierung nachtraeglich
            // dazukam.
            if ($ereignis->eigen) {
                $entfernt += $this->loesche($verbindung, $ereignis->externeId);
                $uebersprungen++;

                continue;
            }

            if (! $ereignis->istBlocker()) {
                // Abgesagt, als "frei" markiert oder ohne verwertbare Zeiten.
                // Wer sich einen Geburtstag eintraegt, ist trotzdem in der
                // Praxis.
                $entfernt += $this->loesche($verbindung, $ereignis->externeId);

                continue;
            }

            $this->schreibe($verbindung, $ereignis);
            $uebernommen++;
        }

        $belegt = $this->blocker->fuer($verbindung->practitioner, $von, $bis);

        $this->merke($verbindung, $seite);

        return new Abgleichergebnis(
            uebernommen: $uebernommen,
            entfernt: $entfernt,
            uebersprungen: $uebersprungen,
            belegteSlots: $belegt,
            voll: $voll,
        );
    }

    /**
     * Delta, wenn ein Token vorliegt -- sonst alles.
     *
     * `410 Gone` ist der vorgesehene Weg zurueck zum Vollabgleich und kein
     * Fehler. Das Token wird verworfen, der zweite Versuch laeuft ohne.
     *
     * @return array{0: Ereignisseite, 1: bool}
     */
    private function hole(
        Kalenderdienst $dienst,
        CalendarConnection $verbindung,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): array {
        $token = is_string($verbindung->sync_token) && $verbindung->sync_token !== ''
            ? $verbindung->sync_token
            : null;

        if ($token === null) {
            return [$dienst->ereignisse($verbindung, null, $von, $bis), true];
        }

        try {
            return [$dienst->ereignisse($verbindung, $token, $von, $bis), false];
        } catch (SyncTokenVerfallen) {
            return [$dienst->ereignisse($verbindung, null, $von, $bis), true];
        }
    }

    private function schreibe(CalendarConnection $verbindung, Ereignis $ereignis): void
    {
        ExternalCalendarBlock::query()->updateOrCreate(
            [
                'calendar_connection_id' => $verbindung->getKey(),
                'external_id' => $ereignis->externeId,
            ],
            [
                'practitioner_id' => $verbindung->practitioner_id,
                'starts_at' => $ereignis->beginn,
                'ends_at' => $ereignis->ende,
                'is_all_day' => $ereignis->ganztaegig,
            ],
        );
    }

    /**
     * Die Kennungen der Events, die wir selbst geschrieben haben.
     *
     * @return array<int, string>
     */
    private function eigeneKennungen(CalendarConnection $verbindung): array
    {
        return CalendarEventLink::query()
            ->where('calendar_connection_id', $verbindung->getKey())
            ->whereNotNull('external_event_id')
            ->pluck('external_event_id')
            ->map(fn (mixed $kennung): string => (string) $kennung)
            ->values()
            ->toArray();
    }

    private function loesche(CalendarConnection $verbindung, string $externeId): int
    {
        return ExternalCalendarBlock::query()
            ->where('calendar_connection_id', $verbindung->getKey())
            ->where('external_id', $externeId)
            ->delete();
    }

    /**
     * Merkt sich den Delta-Zeiger und meldet die Verbindung gesund.
     *
     * Ein Lauf, der durchgelaufen ist, hebt einen frueheren Ausfall auf --
     * sonst bliebe die Warnung im Produkt stehen, nachdem sich das Problem
     * erledigt hat.
     */
    private function merke(CalendarConnection $verbindung, Ereignisseite $seite): void
    {
        if (is_string($seite->zeiger) && $seite->zeiger !== '') {
            $verbindung->sync_token = $seite->zeiger;
        }

        if ($verbindung->status === CalendarConnectionStatus::Expired) {
            $verbindung->status = CalendarConnectionStatus::Active;
        }

        $verbindung->last_synced_at = CarbonImmutable::now();
        $verbindung->last_error = null;
        $verbindung->failed_at = null;
        $verbindung->save();
    }
}
