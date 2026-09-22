<?php

declare(strict_types=1);

namespace App\Verfuegbarkeit;

use App\Enums\AppointmentStatus;
use App\Enums\HoldPurpose;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Contact;
use App\Models\SlotHold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Haelt, gibt frei und wandelt um.
 *
 * Alle drei Vorgaenge fassen **dieselben** Slot-Zeilen an. Besonders die
 * Umwandlung: sie gibt nichts frei und belegt dann neu, sondern tauscht in
 * einer Transaktion `slot_hold_id` gegen `appointment_id`. Wer erst freigibt,
 * oeffnet ein Fenster, in dem jemand dazwischenkommt -- und genau das passiert
 * dann auch, weil der Hold ablief, als die Interessentin gerade zusagte.
 */
final class SlotHalter
{
    /**
     * Haelt die belegte Strecke eines Vorschlags.
     *
     * Die Zeilen werden in **aufsteigender** starts_at-Reihenfolge gesperrt.
     * Zwei Buchungen mit ueberlappenden Strecken wuerden sich sonst
     * gegenseitig blockieren -- ein Deadlock, den MySQL nach Sekunden
     * aufloest, indem es eine der beiden abschiesst.
     */
    public function halte(Slotvorschlag $vorschlag, HoldPurpose $zweck, ?CarbonImmutable $jetzt = null): SlotHold
    {
        $jetzt ??= CarbonImmutable::now();
        $schritt = (int) config('mrs.booking.slot_minutes', 5);
        $benoetigt = (int) ceil($vorschlag->art->belegteDauer() / $schritt);

        return DB::transaction(function () use ($vorschlag, $zweck, $jetzt, $benoetigt): SlotHold {
            $zeilen = $this->sperre($vorschlag);

            if ($zeilen->count() !== $benoetigt) {
                throw SlotNichtVerfuegbar::unvollstaendig($benoetigt, $zeilen->count());
            }

            foreach ($zeilen as $zeile) {
                if (! $zeile->istFrei()) {
                    throw SlotNichtVerfuegbar::vergeben();
                }
            }

            $hold = new SlotHold;
            $hold->appointment_type_id = $vorschlag->art->getKey();
            $hold->practitioner_id = $vorschlag->behandler->getKey();
            $hold->location_id = $vorschlag->standort->getKey();
            $hold->purpose = $zweck;
            $hold->blocked_from = $vorschlag->blockedFrom;
            $hold->blocked_until = $vorschlag->blockedUntil;
            $hold->expires_at = $jetzt->addMinutes($zweck->ttlMinutes());
            $hold->save();

            AppointmentSlot::query()
                ->whereIn('id', $zeilen->pluck('id'))
                ->update(['slot_hold_id' => $hold->getKey()]);

            return $hold;
        });
    }

    /** Gibt einen Hold und seine Zeilen frei. */
    public function gibFrei(SlotHold $hold): void
    {
        DB::transaction(function () use ($hold): void {
            AppointmentSlot::query()
                ->where('slot_hold_id', $hold->getKey())
                ->update(['slot_hold_id' => null]);

            $hold->released_at = now();
            $hold->save();
        });
    }

    /**
     * Wandelt einen Hold in einen Termin um -- dieselben Zeilen.
     *
     * Der Kontakt ist Pflicht (WP-11). Ein Termin ohne Menschen davor ist
     * keine Buchung, sondern eine Sperre -- und dafuer gibt es Abwesenheiten
     * und Schliesszeiten.
     */
    public function wandleUm(
        SlotHold $hold,
        Slotvorschlag $vorschlag,
        Contact $kontakt,
        AppointmentStatus $status = AppointmentStatus::Pending,
    ): Appointment {
        if (! $hold->giltNoch()) {
            throw SlotNichtVerfuegbar::vergeben();
        }

        return DB::transaction(function () use ($hold, $vorschlag, $kontakt, $status): Appointment {
            $zeilen = AppointmentSlot::query()
                ->where('slot_hold_id', $hold->getKey())
                ->orderBy('starts_at')
                ->lockForUpdate()
                ->get();

            if ($zeilen->isEmpty()) {
                throw SlotNichtVerfuegbar::vergeben();
            }

            $termin = new Appointment;
            $termin->contact_id = $kontakt->getKey();
            $termin->appointment_type_id = $vorschlag->art->getKey();
            $termin->practitioner_id = $vorschlag->behandler->getKey();
            $termin->location_id = $vorschlag->standort->getKey();
            $termin->starts_at = $vorschlag->startsAt;
            $termin->ends_at = $vorschlag->endsAt;
            $termin->blocked_from = $vorschlag->blockedFrom;
            $termin->blocked_until = $vorschlag->blockedUntil;
            $termin->status = $status;
            $termin->save();

            // Ein Zug: Hold raus, Termin rein. Kein Zwischenzustand, in dem
            // die Zeilen frei waeren.
            AppointmentSlot::query()
                ->whereIn('id', $zeilen->pluck('id'))
                ->update([
                    'slot_hold_id' => null,
                    'appointment_id' => $termin->getKey(),
                ]);

            $hold->released_at = now();
            $hold->save();

            return $termin;
        });
    }

    /**
     * Gibt die Zeilen abgelaufener Holds frei.
     *
     * Reine Aufraeumarbeit. Die Slots gelten schon vorher als frei -- der
     * Scope `frei` prueft gegen die Uhr. Dieser Job haelt nur die Tabelle
     * sauber.
     */
    public function raeumeAbgelaufeneAuf(): int
    {
        $abgelaufen = SlotHold::query()
            ->whereNull('released_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($abgelaufen as $hold) {
            $this->gibFrei($hold);
        }

        return $abgelaufen->count();
    }

    /**
     * @return Collection<int, AppointmentSlot>
     */
    private function sperre(Slotvorschlag $vorschlag): Collection
    {
        return AppointmentSlot::query()
            // Der Hold kommt mit: istFrei() braucht seine Ablaufzeit, und eine
            // Abfrage je gesperrter Zeile waere bei neun Zeilen neun Abfragen.
            ->with('hold')
            ->where('practitioner_id', $vorschlag->behandler->getKey())
            ->where('location_id', $vorschlag->standort->getKey())
            ->where('starts_at', '>=', $vorschlag->blockedFrom)
            ->where('starts_at', '<', $vorschlag->blockedUntil)
            ->orderBy('starts_at')
            ->lockForUpdate()
            ->get();
    }
}
