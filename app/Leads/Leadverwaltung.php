<?php

declare(strict_types=1);

namespace App\Leads;

use App\Enums\AppointmentStatus;
use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Treatment;
use Carbon\CarbonImmutable;

/**
 * Entscheidung D4 an **einer** Stelle.
 *
 * Wann ein neuer Lead entsteht, ist die Regel, die dieses Paket traegt -- und
 * sie kippt in beide Richtungen:
 *
 * - Fuer jede Nachricht einen Lead anzulegen ergibt **Lead-Inflation**: die
 *   Kosten pro Lead sehen kuenstlich gut aus, die Pipeline ist unbrauchbar.
 * - Alles an einen Lead zu haengen ergibt **Lead-Verklumpung**: eine Person
 *   mit fuenf Anfragen ueber zwei Jahre ist ein Vorgang, und die Auswertung
 *   verliert vier davon.
 *
 * Vier Aufrufstellen mit je eigener Auslegung waeren vier Gelegenheiten, das
 * falsch zu machen. Deshalb steht es hier.
 */
final class Leadverwaltung
{
    /** Nimmt eine Anfrage auf -- und entscheidet dabei, ob sie eine neue ist. */
    public function erfasse(
        Contact $kontakt,
        ?Treatment $behandlung,
        LeadSource $quelle,
        ?CarbonImmutable $jetzt = null,
    ): Lead {
        $jetzt ??= CarbonImmutable::now();

        $offener = $this->offenerLead($kontakt, $behandlung, $jetzt);

        if ($offener instanceof Lead) {
            // Kein neuer Vorgang, aber ein Lebenszeichen: die Frist aus D4
            // zaehlt ab der letzten Aktivitaet.
            $offener->last_activity_at = $jetzt;
            $offener->save();

            return $offener;
        }

        $lead = new Lead;
        $lead->contact_id = $kontakt->getKey();
        $lead->treatment_id = $behandlung?->getKey();
        $lead->status = LeadStatus::New;
        $lead->source = $quelle;
        $lead->last_activity_at = $jetzt;
        $lead->save();

        return $lead;
    }

    /**
     * Der offene Lead, der eine neue Anfrage aufnimmt -- oder null.
     *
     * Drei Gruende fuer einen neuen Vorgang, alle aus D4: es gibt keinen
     * offenen, die Behandlung weicht ab, oder der letzte ist zu lange still.
     */
    public function offenerLead(Contact $kontakt, ?Treatment $behandlung, CarbonImmutable $jetzt): ?Lead
    {
        $frist = $jetzt->subDays((int) config('mrs.leads.reopen_after_inactive_days', 90));

        return Lead::query()
            ->offen()
            ->where('contact_id', $kontakt->getKey())
            ->where('last_activity_at', '>', $frist)
            ->when(
                $behandlung instanceof Treatment,
                fn ($abfrage) => $abfrage->where('treatment_id', $behandlung?->getKey()),
                fn ($abfrage) => $abfrage->whereNull('treatment_id'),
            )
            ->orderByDesc('last_activity_at')
            ->first();
    }

    /**
     * Der Lead folgt dem Termin, nicht umgekehrt.
     *
     * Ein Termin ist eine Reaktion: er setzt Speed-to-Lead, wenn noch nichts
     * gesetzt war.
     */
    public function beiTermin(Appointment $termin, LeadSource $quelle, ?CarbonImmutable $jetzt = null): Lead
    {
        $jetzt ??= CarbonImmutable::now();

        $lead = $this->erfasse(
            $termin->contact,
            $termin->appointmentType->treatment,
            $quelle,
            $jetzt,
        );

        $lead->vermerkeReaktion($jetzt);
        $lead->status = LeadStatus::Scheduled;
        $lead->last_activity_at = $jetzt;
        $lead->save();

        return $lead;
    }

    /**
     * Ein erschienener Termin gewinnt den Lead.
     *
     * **Gewonnen heisst erschienen, nicht gebucht.** Ein Termin, den niemand
     * wahrnimmt, darf nicht als Abschluss zaehlen -- sonst misst der ROAS
     * Absichten statt Umsatz (docs/fachlogik/attribution.md, Kennzahlen).
     */
    public function beiStatus(Appointment $termin, AppointmentStatus $neu, ?CarbonImmutable $jetzt = null): void
    {
        $jetzt ??= CarbonImmutable::now();
        $lead = $this->zumTermin($termin);

        if (! $lead instanceof Lead) {
            return;
        }

        $lead->vermerkeReaktion($jetzt);
        $lead->last_activity_at = $jetzt;

        if ($neu === AppointmentStatus::Attended) {
            $lead->status = LeadStatus::Won;
            $lead->closed_at = $jetzt;
        }

        $lead->save();
    }

    /** Eine Absage oeffnet den Vorgang wieder -- er ist ja nicht erledigt. */
    public function beiAbsage(Appointment $termin, ?CarbonImmutable $jetzt = null): void
    {
        $jetzt ??= CarbonImmutable::now();
        $lead = $this->zumTermin($termin);

        if (! $lead instanceof Lead || ! $lead->status->istOffen()) {
            return;
        }

        $lead->status = LeadStatus::Contacted;
        $lead->last_activity_at = $jetzt;
        $lead->save();
    }

    /** Von Hand: die Praxis hat reagiert. */
    public function vermerkeReaktion(Lead $lead, ?CarbonImmutable $jetzt = null): Lead
    {
        $jetzt ??= CarbonImmutable::now();

        $lead->vermerkeReaktion($jetzt);

        if ($lead->status === LeadStatus::New) {
            $lead->status = LeadStatus::Contacted;
        }

        $lead->last_activity_at = $jetzt;
        $lead->save();

        return $lead;
    }

    /** Verloren verlangt einen Grund -- sonst ist die Pipeline ein Friedhof. */
    public function gibAuf(Lead $lead, LeadLostReason $grund, ?CarbonImmutable $jetzt = null): Lead
    {
        $jetzt ??= CarbonImmutable::now();

        $lead->status = LeadStatus::Lost;
        $lead->lost_reason = $grund;
        $lead->closed_at = $jetzt;
        $lead->last_activity_at = $jetzt;
        $lead->save();

        return $lead;
    }

    /**
     * Der Lead zu einem Termin.
     *
     * Ueber Kontakt und Behandlung, nicht ueber einen Fremdschluessel: ein
     * Termin kann aus einem Lead entstehen, muss aber nicht -- die
     * Stammkundin ruft an, und niemand hat vorher eine Anfrage erfasst.
     */
    private function zumTermin(Appointment $termin): ?Lead
    {
        $behandlung = $termin->appointmentType->treatment;

        return Lead::query()
            ->where('contact_id', $termin->contact_id)
            ->when(
                $behandlung instanceof Treatment,
                fn ($abfrage) => $abfrage->where('treatment_id', $behandlung?->getKey()),
                fn ($abfrage) => $abfrage->whereNull('treatment_id'),
            )
            ->orderByDesc('last_activity_at')
            ->first();
    }
}
