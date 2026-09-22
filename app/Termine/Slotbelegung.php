<?php

declare(strict_types=1);

namespace App\Termine;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use App\Verfuegbarkeit\SlotNichtVerfuegbar;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Die einzige Stelle, an der Slot-Zeilen einem Termin zugewiesen werden.
 *
 * Alles hier laeuft **innerhalb einer Transaktion des Aufrufers** und auf
 * Zeilen, die vorher mit SELECT ... FOR UPDATE gesperrt wurden. Gesperrt wird
 * in fester Reihenfolge -- erst nach Behandler, dann nach Zeit. Ohne diese
 * Ordnung erzeugen zwei Verschiebungen, die sich kreuzen (A von P1 nach P2,
 * B von P2 nach P1), einen Deadlock.
 */
final class Slotbelegung
{
    /** Belegt die Zielstrecke fuer einen Termin, der noch keine Zeilen hat. */
    public function belege(Appointment $termin, Slotvorschlag $ziel, bool $uebersteuern): void
    {
        $this->weiseZu($termin, $ziel, $uebersteuern, mitAltbestand: false);
    }

    /**
     * Verlegt einen Termin auf eine andere Strecke.
     *
     * Alte und neue Strecke duerfen sich ueberlappen -- wer um zehn Minuten
     * schiebt, ueberlappt sich fast vollstaendig selbst.
     */
    public function verlege(Appointment $termin, Slotvorschlag $ziel, bool $uebersteuern): void
    {
        $this->weiseZu($termin, $ziel, $uebersteuern, mitAltbestand: true);
    }

    /**
     * Gibt alle Zeilen eines Termins frei.
     *
     * Der Termin selbst bleibt bestehen. Eine Absage ist keine Loeschung:
     * ohne die Zeile gaebe es keine No-Show-Quote und keine Absagequote.
     */
    public function gibFrei(Appointment $termin): int
    {
        return AppointmentSlot::query()
            ->where('appointment_id', $termin->getKey())
            ->update(['appointment_id' => null]);
    }

    private function weiseZu(
        Appointment $termin,
        Slotvorschlag $ziel,
        bool $uebersteuern,
        bool $mitAltbestand,
    ): void {
        $schritt = $this->schritt();
        $this->pruefeRaster($ziel, $schritt);

        if ($uebersteuern) {
            $this->ergaenzeZeilen($ziel, $schritt);
        }

        $gesperrt = $this->sperre($termin, $ziel, $mitAltbestand);

        $zielzeilen = $gesperrt->filter(
            fn (AppointmentSlot $zeile): bool => $zeile->practitioner_id === $ziel->behandler->getKey()
                && $zeile->starts_at >= $ziel->blockedFrom
                && $zeile->starts_at < $ziel->blockedUntil
        );

        // V11 und zugleich V1: fehlt eine Zeile, liegt der Zeitraum ganz oder
        // teilweise ausserhalb der Arbeitszeit. Ohne Uebersteuern ist das
        // keine Belegung, sondern schlicht keine Sprechstunde.
        $benoetigt = (int) ceil($ziel->art->belegteDauer() / $schritt);

        if ($zielzeilen->count() !== $benoetigt) {
            throw $uebersteuern
                ? SlotNichtVerfuegbar::unvollstaendig($benoetigt, $zielzeilen->count())
                : NichtBuchbar::keineArbeitszeit();
        }

        // V4, V5, V6 -- und die sind **nicht** uebersteuerbar.
        foreach ($zielzeilen as $zeile) {
            if (! $zeile->istFreiFuer($termin)) {
                throw SlotNichtVerfuegbar::vergeben();
            }
        }

        // Erst freigeben, dann belegen. Andersherum loescht der
        // Freigabeschritt die gerade gesetzte Belegung der Ueberlappung
        // wieder -- und der Termin stuende ohne Slots da.
        if ($mitAltbestand) {
            $this->gibFrei($termin);
        }

        AppointmentSlot::query()
            ->whereIn('id', $zielzeilen->pluck('id'))
            ->update([
                'appointment_id' => $termin->getKey(),
                // Der Verweis auf einen abgelaufenen Hold muss mit weg. Sonst
                // traegt die Zeile zwei Belegungen -- und die Tabelle sagt,
                // genau eine der drei Spalten sei gesetzt. Eine Zeile, die
                // zwei Dinge gleichzeitig behauptet, ist der Anfang einer
                // Fehlersuche, die niemand gewinnt.
                'slot_hold_id' => null,
            ]);
    }

    /**
     * Sperrt die Zielstrecke und, beim Verschieben, die bisherigen Zeilen.
     *
     * @return Collection<int, AppointmentSlot>
     */
    private function sperre(
        Appointment $termin,
        Slotvorschlag $ziel,
        bool $mitAltbestand,
    ): Collection {
        $abfrage = AppointmentSlot::query()
            // istFreiFuer() braucht die Ablaufzeit des Holds. Ohne dieses
            // Vorladen waere das eine Abfrage je gesperrter Zeile.
            ->with('hold')
            // **Die Klammer ist nicht kosmetisch.** Der globale Scope der
            // Mandantentrennung haengt seine Bedingung an das Ende der
            // WHERE-Liste. Stuende das ODER unten auf derselben Ebene, hiesse
            // die Bedingung "(Zielstrecke) ODER (eigene Zeilen UND Mandant)"
            // -- und die Zielstrecke waere mandantenlos. Regel 1 haengt hier
            // an einer Klammer.
            ->where(function (Builder $gruppe) use ($termin, $ziel, $mitAltbestand): void {
                $gruppe->where(function (Builder $zielstrecke) use ($ziel): void {
                    $zielstrecke
                        ->where('practitioner_id', $ziel->behandler->getKey())
                        ->where('starts_at', '>=', $ziel->blockedFrom)
                        ->where('starts_at', '<', $ziel->blockedUntil);
                });

                if ($mitAltbestand) {
                    $gruppe->orWhere('appointment_id', $termin->getKey());
                }
            });

        /** @var Collection<int, AppointmentSlot> */
        return $abfrage
            ->orderBy('practitioner_id')
            ->orderBy('starts_at')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Legt die fehlenden Zeilen der Zielstrecke an.
     *
     * Nur beim Uebersteuern. Ausserhalb der Arbeitszeit gibt es gar keine
     * Slot-Zeilen -- WP-10 materialisiert nur, wo jemand arbeitet. Wer hier
     * nur pruefte statt anzulegen, bekaeme einen Termin ohne Slot-Belegung:
     * unsichtbar fuer jede spaetere Buchung und damit genau die
     * Doppelbuchung, die A9 ausschliessen soll.
     *
     * `insertOrIgnore` haelt dabei den Unique-Index
     * (practitioner_id, starts_at) als Schiedsrichter -- eine Pruefung in PHP
     * saehe die zweite gleichzeitige Uebersteuerung nicht.
     */
    private function ergaenzeZeilen(Slotvorschlag $ziel, int $schritt): void
    {
        $organisation = app(TenantContext::class)->requireId();
        $zeilen = [];

        for (
            $zeiger = $ziel->blockedFrom;
            $zeiger < $ziel->blockedUntil;
            $zeiger = $zeiger->addMinutes($schritt)
        ) {
            $zeilen[] = [
                'id' => Uuid::generate(),
                'organization_id' => $organisation,
                'practitioner_id' => $ziel->behandler->getKey(),
                'location_id' => $ziel->standort->getKey(),
                'starts_at' => $zeiger->format('Y-m-d H:i:s'),
            ];
        }

        DB::table('appointment_slots')->insertOrIgnore($zeilen);
    }

    /**
     * Die belegte Strecke muss auf dem Raster liegen.
     *
     * Eine Uebersteuerung mit einer von Hand eingetippten Uhrzeit ist der
     * einzige Weg, auf dem eine krumme Zeit hereinkommt. 09:02 Uhr ergaebe
     * Zeilen, die zu keiner erzeugten Zeile passen -- der Termin belegte
     * seine Zeit dann neben allen anderen statt zwischen ihnen.
     */
    private function pruefeRaster(Slotvorschlag $ziel, int $schritt): void
    {
        foreach ([$ziel->blockedFrom, $ziel->blockedUntil] as $zeitpunkt) {
            if (! $this->liegtAufRaster($zeitpunkt, $schritt)) {
                throw new InvalidArgumentException(
                    "Zeitpunkte müssen auf dem {$schritt}-Minuten-Raster liegen, "
                    .$zeitpunkt->format('H:i:s').' tut das nicht.'
                );
            }
        }
    }

    private function liegtAufRaster(CarbonImmutable $zeitpunkt, int $schritt): bool
    {
        return $zeitpunkt->second === 0 && $zeitpunkt->minute % $schritt === 0;
    }

    private function schritt(): int
    {
        return (int) config('mrs.booking.slot_minutes', 5);
    }
}
