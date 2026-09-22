<?php

declare(strict_types=1);

namespace App\Warteliste;

use App\Enums\WaitlistTrigger;
use App\Jobs\LueckeFuellen;
use App\Models\Appointment;
use App\Support\Uuid;
use App\Verfuegbarkeit\Slotvorschlag;

/**
 * Meldet eine frei gewordene Luecke an die Warteliste.
 *
 * **Eine Stelle, vier Ausloeser** (docs/fachlogik/warteliste.md): Absage,
 * Verschiebung, ausbleibende Reaktion, Freigabe durch das Team. Der
 * Terminplaner kennt nur diese Klasse und nicht die Warteliste -- sonst
 * haette jede Terminaenderung eine Meinung darueber, wer als naechstes
 * drankommt.
 */
final class Lueckenmelder
{
    /** Der Zeitraum eines abgesagten Termins wird frei. */
    public function beiAbsage(Appointment $termin): void
    {
        $this->melde($termin, WaitlistTrigger::Cancellation);
    }

    /**
     * Die **alte** Zeit einer Verschiebung wird frei.
     *
     * Aufgerufen wird sie mit den Werten von vorher -- nach dem Speichern
     * traegt die Zeile die neuen.
     */
    public function beiVerschiebung(Appointment $termin, Slotvorschlag $alt): void
    {
        $this->sende(
            $termin,
            $alt,
            WaitlistTrigger::Reschedule,
        );
    }

    /**
     * Ein wackeliger Termin: keine Reaktion auf die Erinnerung.
     *
     * **Parallel, ohne Hold** -- der Slot ist noch belegt, und der
     * bestehende Termin wird nicht angetastet.
     */
    public function beiAusbleibenderReaktion(Appointment $termin): void
    {
        $this->melde($termin, WaitlistTrigger::NoResponse);
    }

    /** Freigabe durch das Team. */
    public function beiFreigabe(Appointment $termin): void
    {
        $this->melde($termin, WaitlistTrigger::Manual);
    }

    private function melde(Appointment $termin, WaitlistTrigger $ausloeser): void
    {
        $this->sende($termin, new Slotvorschlag(
            art: $termin->appointmentType,
            behandler: $termin->practitioner,
            standort: $termin->location,
            blockedFrom: $termin->blocked_from,
            blockedUntil: $termin->blocked_until,
            startsAt: $termin->starts_at,
            endsAt: $termin->ends_at,
        ), $ausloeser);
    }

    private function sende(Appointment $termin, Slotvorschlag $slot, WaitlistTrigger $ausloeser): void
    {
        LueckeFuellen::dispatch(
            Uuid::toString($termin->organization_id),
            (string) $slot->art->uuid,
            (string) $slot->behandler->uuid,
            (string) $slot->standort->uuid,
            $slot->startsAt->toIso8601String(),
            $slot->endsAt->toIso8601String(),
            $slot->blockedFrom->toIso8601String(),
            $slot->blockedUntil->toIso8601String(),
            $ausloeser,
        );
    }
}
