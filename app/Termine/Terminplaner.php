<?php

declare(strict_types=1);

namespace App\Termine;

use App\Benachrichtigung\Terminbenachrichtigungen;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancellationReason;
use App\Enums\LeadSource;
use App\Kalender\Terminkalender;
use App\Leads\Leadverwaltung;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\SlotHold;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use App\Warteliste\Lueckenmelder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Buchen, verschieben, absagen, Status setzen.
 *
 * Die elf Bedingungen aus docs/fachlogik/verfuegbarkeit.md zerfallen hier in
 * zwei Gruppen, und das ist die eigentliche Entscheidung dieses Pakets:
 *
 * | Gruppe | Bedingungen | Aussage | uebersteuerbar |
 * |---|---|---|---|
 * | Angebot  | V1, V2, V3, V7, V8, V9, V10 | *soll* angeboten werden | ja |
 * | Belegung | V4, V5, V6, V11             | ist **schon vergeben**  | nein |
 *
 * Uebersteuern heisst "ich weiss, dass das nicht angeboten wird, ich mache es
 * trotzdem" -- nicht "ich buche ueber jemanden drueber". Die Empfangskraft,
 * die der Stammkundin um 18:30 noch einen Termin gibt, obwohl um 18:00
 * Feierabend ist, tut etwas Richtiges. Die Empfangskraft, die einen Termin auf
 * eine belegte Zeit legt, tut etwas, das sich hinterher niemand erklaeren kann.
 *
 * Die Belegungsgruppe wird nie hier geprueft, sondern in Slotbelegung -- auf
 * gesperrten Zeilen, innerhalb der Transaktion. Eine Pruefung davor waere eine
 * Aussage ueber die Vergangenheit.
 */
final class Terminplaner
{
    public function __construct(
        private readonly Slotbelegung $belegung,
        private readonly Verfuegbarkeit $verfuegbarkeit,
        private readonly Statusautomat $statusautomat,
        private readonly SlotHalter $halter,
        private readonly Terminbenachrichtigungen $nachrichten,
        private readonly Terminkalender $kalender,
        private readonly Leadverwaltung $leads,
        private readonly Lueckenmelder $luecken,
    ) {}

    /**
     * Legt einen Termin an.
     *
     * Der Standardstatus ist `confirmed`: wer am Empfang sitzt und einen
     * Termin eintraegt, hat gerade mit der Person gesprochen. Die
     * oeffentliche Buchungsseite (WP-12) und der Agent (WP-24) buchen
     * `pending`.
     */
    public function buche(
        Slotvorschlag $vorschlag,
        Contact $kontakt,
        BookingChannel $kanal = BookingChannel::Internal,
        AppointmentStatus $status = AppointmentStatus::Confirmed,
        bool $uebersteuern = false,
        ?CarbonImmutable $jetzt = null,

        /**
         * Woher dieser Termin kam.
         *
         * Ohne Angabe die Vorgabe des Kanals. **Beim internen Anlegen ist sie
         * Pflicht** (docs/fachlogik/attribution.md, Testfall 6): ein Teil der
         * Anzeigen-Leads ruft an, und ohne dieses Feld fehlen diese
         * Buchungen in der Auswertung.
         */
        ?LeadSource $quelle = null,
    ): Appointment {
        $jetzt ??= CarbonImmutable::now();

        $this->pruefeGrunddaten($vorschlag, neu: true);

        if (! $uebersteuern) {
            $this->pruefeAngebot($vorschlag, $jetzt);
        }

        return DB::transaction(function () use ($vorschlag, $kontakt, $kanal, $status, $uebersteuern, $quelle): Appointment {
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
            $termin->booked_via = $kanal;
            $termin->is_override = $uebersteuern;
            $termin->save();

            $this->belegung->belege($termin, $vorschlag, $uebersteuern);

            // Innerhalb der Transaktion: ein Termin ohne geplante Erinnerung
            // waere ein Termin, an den niemand erinnert wird -- und das faellt
            // erst auf, wenn jemand nicht erscheint.
            $this->nachrichten->beiBuchung($termin, $status === AppointmentStatus::Confirmed);
            $this->kalender->beiBuchung($termin);
            $this->leads->beiTermin($this->fuerLead($termin), $quelle ?? $kanal->alsLeadquelle());

            return $termin;
        });
    }

    /**
     * Loest eine Reservierung ein.
     *
     * Der Weg der oeffentlichen Buchungsseite (WP-12), des Agenten (WP-24)
     * und der Warteliste (WP-25): der Slot ist bereits gehalten, die
     * Verfuegbarkeit wurde beim Halten geprueft. Zwischen Hold und Einloesung
     * kann ihn niemand wegnehmen -- genau dafuer gibt es ihn.
     *
     * Die Slot-Zeilen wechseln in SlotHalter::wandleUm() von der Reservierung
     * auf den Termin; die beiden Felder, die den Termin erst zu einer
     * oeffentlichen Buchung machen, kommen hier dazu. Beides in **einer**
     * Transaktion: ein Termin ohne Buchungskanal waere in der Auswertung
     * eine Buchung vom Empfang, und das ist eine andere Aussage.
     */
    public function loeseEin(
        SlotHold $hold,
        Slotvorschlag $vorschlag,
        Contact $kontakt,
        BookingChannel $kanal,
        AppointmentStatus $status = AppointmentStatus::Pending,
        ?CarbonImmutable $einwilligung = null,
    ): Appointment {
        return DB::transaction(function () use ($hold, $vorschlag, $kontakt, $kanal, $status, $einwilligung): Appointment {
            $termin = $this->halter->wandleUm($hold, $vorschlag, $kontakt, $status);

            $termin->booked_via = $kanal;
            $termin->consent_accepted_at = $einwilligung;
            $termin->save();

            $this->nachrichten->beiBuchung($termin, $status === AppointmentStatus::Confirmed);
            $this->kalender->beiBuchung($termin);
            $this->leads->beiTermin($this->fuerLead($termin), $kanal->alsLeadquelle());

            return $termin;
        });
    }

    /**
     * Verschiebt einen Termin -- dieselbe Zeile.
     *
     * Nicht absagen und neu anlegen: Erinnerungen (WP-13), Kalendersync
     * (WP-14) und Attribution (WP-32) haengen an der Identitaet des Termins,
     * und eine Absage plus Neuanlage zaehlt in der Auswertung zweimal
     * "gebucht" und einmal "abgesagt", obwohl nichts davon stattgefunden hat.
     *
     * Die Terminart wird hier **nicht** erneut auf `is_active` geprueft. Sie
     * wird ja nicht neu gewaehlt -- und eine Praxis, die eine Terminart
     * einstellt, muss ihre bestehenden Termine trotzdem verschieben koennen.
     */
    public function verschiebe(
        Appointment $termin,
        Slotvorschlag $ziel,
        bool $uebersteuern = false,
        ?CarbonImmutable $jetzt = null,
    ): Appointment {
        $jetzt ??= CarbonImmutable::now();

        if ($termin->status === AppointmentStatus::Cancelled) {
            throw TerminNichtAenderbar::abgesagt();
        }

        if (! $termin->istAenderbar()) {
            throw TerminNichtAenderbar::nichtMehrVerschiebbar($termin->status);
        }

        $this->pruefeGrunddaten($ziel, neu: false);

        if (! $uebersteuern) {
            $this->pruefeAngebot($ziel, $jetzt);
        }

        // Die alte Strecke, bevor sie ueberschrieben wird: sie wird frei und
        // gehoert der Warteliste (WP-25).
        $alt = new Slotvorschlag(
            art: $termin->appointmentType,
            behandler: $termin->practitioner,
            standort: $termin->location,
            blockedFrom: $termin->blocked_from,
            blockedUntil: $termin->blocked_until,
            startsAt: $termin->starts_at,
            endsAt: $termin->ends_at,
        );

        return DB::transaction(function () use ($termin, $ziel, $alt, $uebersteuern): Appointment {
            // Erst die Zeilen. Schlaegt die Belegung fehl, bleibt der Termin
            // unveraendert -- er ist zu diesem Zeitpunkt noch nicht angefasst.
            $this->belegung->verlege($termin, $ziel, $uebersteuern);

            $termin->appointment_type_id = $ziel->art->getKey();
            $termin->practitioner_id = $ziel->behandler->getKey();
            $termin->location_id = $ziel->standort->getKey();
            $termin->starts_at = $ziel->startsAt;
            $termin->ends_at = $ziel->endsAt;
            $termin->blocked_from = $ziel->blockedFrom;
            $termin->blocked_until = $ziel->blockedUntil;

            if ($uebersteuern) {
                $termin->is_override = true;
            }

            $termin->save();

            $this->nachrichten->beiVerschiebung($termin);
            $this->kalender->beiVerschiebung($termin);
            $this->luecken->beiVerschiebung($termin, $alt);

            return $termin;
        });
    }

    /**
     * Sagt einen Termin ab und gibt seine Zeit frei.
     *
     * Keine Loeschung. Ohne die Zeile gibt es keine No-Show-Quote, keine
     * Absagequote und keine Grundlage fuer die Auswertung in WP-32.
     */
    public function sageAb(
        Appointment $termin,
        CancellationReason $grund,
        ?CarbonImmutable $jetzt = null,
    ): Appointment {
        $jetzt ??= CarbonImmutable::now();

        $this->statusautomat->pruefe($termin, AppointmentStatus::Cancelled, $jetzt);

        return DB::transaction(function () use ($termin, $grund, $jetzt): Appointment {
            $this->belegung->gibFrei($termin);

            $termin->status = AppointmentStatus::Cancelled;
            $termin->cancelled_at = $jetzt;
            $termin->cancellation_reason = $grund;
            $termin->save();

            $this->nachrichten->beiAbsage($termin);
            $this->kalender->beiAbsage($termin);
            $this->leads->beiAbsage($this->fuerLead($termin), $jetzt);

            // **Die Luecke geht an die Warteliste** (WP-25). Der Auftrag
            // laeuft nach dem Commit: ein Angebot auf eine Absage, die noch
            // zurueckgerollt werden koennte, waere eines zu viel.
            $this->luecken->beiAbsage($termin);

            return $termin;
        });
    }

    /**
     * Setzt den Status.
     *
     * "Erschienen" und "Nicht erschienen" behalten ihre Slots: der Termin hat
     * stattgefunden, die Zeit war belegt. Nur die Absage gibt frei.
     */
    public function setzeStatus(
        Appointment $termin,
        AppointmentStatus $neu,
        ?CarbonImmutable $jetzt = null,
    ): Appointment {
        if ($neu === AppointmentStatus::Cancelled) {
            throw TerminNichtAenderbar::absageBrauchtGrund();
        }

        $jetzt ??= CarbonImmutable::now();

        $this->statusautomat->pruefe($termin, $neu, $jetzt);

        $termin->status = $neu;
        $termin->save();

        if ($neu === AppointmentStatus::Confirmed) {
            $this->nachrichten->beiBestaetigung($termin);
        }

        // Der Lead folgt dem Termin: erschienen heisst gewonnen (WP-17).
        $this->leads->beiStatus($this->fuerLead($termin), $neu, $jetzt);

        return $termin;
    }

    /**
     * Der Termin mit den Beziehungen, die die Leadverwaltung braucht.
     *
     * Strenge Modelle lassen kein Nachladen zu -- und innerhalb der
     * Transaktion ist frisch Gespeichertes noch ohne Beziehungen.
     */
    private function fuerLead(Appointment $termin): Appointment
    {
        return $termin->loadMissing(['contact', 'appointmentType.treatment']);
    }

    /**
     * Was auch beim Uebersteuern gilt.
     *
     * Ein Behandler, der an diesem Standort gar nicht arbeitet, ist keine
     * Frage des Angebots, sondern der Stammdaten -- und ein inaktiver
     * Standort ist zu. Beides waere mit Uebersteuern nicht besser.
     */
    private function pruefeGrunddaten(Slotvorschlag $vorschlag, bool $neu): void
    {
        if ($neu && ! $vorschlag->art->is_active) {
            throw NichtBuchbar::ausserhalbDesAngebots();
        }

        if (! $vorschlag->behandler->is_active || ! $vorschlag->standort->is_active) {
            throw NichtBuchbar::ausserhalbDesAngebots();
        }

        $arbeitetHier = $vorschlag->behandler
            ->locations()
            ->whereKey($vorschlag->standort->getKey())
            ->exists();

        if (! $arbeitetHier) {
            throw NichtBuchbar::keineArbeitszeit();
        }
    }

    /**
     * Die uebersteuerbare Gruppe: V2, V3, V7, V8, V9, V10.
     *
     * V1 fehlt hier mit Absicht. "Es gibt eine Arbeitszeit" ist gleichbedeutend
     * damit, dass WP-10 Slot-Zeilen materialisiert hat -- das stellt sich in
     * Slotbelegung heraus, auf den gesperrten Zeilen, und nicht vorher.
     */
    private function pruefeAngebot(Slotvorschlag $vorschlag, CarbonImmutable $jetzt): void
    {
        // V7, V8
        if (! $vorschlag->art->wirdAngebotenVon($vorschlag->behandler, $vorschlag->standort)) {
            throw NichtBuchbar::ausserhalbDesAngebots();
        }

        // V9
        if (! $vorschlag->art->istBuchbarAm($vorschlag->startsAt, $jetzt)) {
            throw NichtBuchbar::innerhalbDerVorlaufzeit($vorschlag->art->lead_time_hours);
        }

        // V10
        $tage = (int) config('mrs.booking.horizon_days', 90);

        if ($vorschlag->startsAt > $jetzt->addDays($tage)) {
            throw NichtBuchbar::jenseitsDesHorizonts($tage);
        }

        // V2, V3 -- ueber die **ganze** belegte Strecke, nicht nur ihren Beginn
        $gesperrt = $this->verfuegbarkeit->istGesperrt(
            $vorschlag->behandler,
            $vorschlag->standort,
            $vorschlag->blockedFrom,
            $vorschlag->blockedUntil,
        );

        if ($gesperrt) {
            throw NichtBuchbar::abwesendOderGeschlossen();
        }
    }
}
