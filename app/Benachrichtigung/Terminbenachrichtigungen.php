<?php

declare(strict_types=1);

namespace App\Benachrichtigung;

use App\Enums\NotificationChannel;
use App\Enums\NotificationKind;
use App\Jobs\TerminnachrichtVersenden;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Plant und verschickt die Nachrichten zu einem Termin.
 *
 * Die Klasse legt Zeilen an und stellt Jobs ein -- sie verschickt nichts
 * selbst. Regel 4: kein schreibender Fremdsystemzugriff im Anfragezyklus.
 */
final class Terminbenachrichtigungen
{
    /** Plant alles, was zu einer neuen Buchung gehoert. */
    public function beiBuchung(Appointment $termin, bool $bestaetigt): void
    {
        $this->sofort($termin, $bestaetigt ? NotificationKind::Confirmation : NotificationKind::RequestReceived);
        $this->planeErinnerung($termin);
    }

    /**
     * Verschieben setzt die Erinnerung zurueck.
     *
     * Ohne das erinnert das System an die alte Zeit -- oder gar nicht, weil
     * die Zeile schon als verschickt gilt.
     */
    public function beiVerschiebung(Appointment $termin): void
    {
        $this->entferne($termin, NotificationKind::Reminder);
        $this->entferne($termin, NotificationKind::Rescheduled);

        $this->sofort($termin, NotificationKind::Rescheduled);
        $this->planeErinnerung($termin);
    }

    /**
     * Absagen loescht die offene Erinnerung.
     *
     * Eine Erinnerung an einen abgesagten Termin ist der peinlichste Fehler
     * dieser Gattung.
     */
    public function beiAbsage(Appointment $termin): void
    {
        $this->entferne($termin, NotificationKind::Reminder);

        $this->sofort($termin, NotificationKind::Cancellation);
    }

    public function beiBestaetigung(Appointment $termin): void
    {
        $this->sofort($termin, NotificationKind::Confirmation);
    }

    /**
     * Legt eine Zeile an und stellt den Versand ein.
     *
     * Die Zeile kann es schon geben -- ein Termin laesst sich zweimal
     * bestaetigen. Der Unique-Index entscheidet, nicht eine Abfrage davor.
     */
    private function sofort(Appointment $termin, NotificationKind $art): void
    {
        $zeile = $this->lege($termin, $art, null);

        if ($zeile instanceof AppointmentNotification) {
            // **Kanonische UUIDs, keine Rohbytes.** Der Primaerschluessel ist
            // BINARY(16) (Entscheidung A4); in einer Job-Nutzlast bricht er
            // json_encode() mit "Malformed UTF-8 characters" -- und die
            // Meldung zeigt auf die Queue statt auf die Ursache.
            TerminnachrichtVersenden::dispatch(
                (string) $zeile->uuid,
                (string) $this->organisation()->uuid,
            );
        }
    }

    private function planeErinnerung(Appointment $termin): void
    {
        $vorlauf = $this->vorlaufStunden();
        $zeitpunkt = $termin->starts_at->subHours($vorlauf);

        // Ein Termin in zwei Stunden bekommt keine Erinnerung fuer gestern.
        // Die Zeile entsteht trotzdem: der Versandbefehl entscheidet, und er
        // schickt nichts in die Vergangenheit.
        $this->lege($termin, NotificationKind::Reminder, $zeitpunkt);
    }

    private function lege(Appointment $termin, NotificationKind $art, ?CarbonImmutable $zeitpunkt): ?AppointmentNotification
    {
        try {
            $zeile = new AppointmentNotification;
            $zeile->appointment_id = $termin->getKey();
            $zeile->kind = $art;
            $zeile->channel = NotificationChannel::Email;
            $zeile->scheduled_for = $zeitpunkt;
            $zeile->save();

            return $zeile;
        } catch (QueryException $ausnahme) {
            // Die Zeile gibt es schon. Genau dafuer ist der Index da.
            if (str_contains($ausnahme->getMessage(), 'benachrichtigung_art_unique')) {
                return null;
            }

            throw $ausnahme;
        }
    }

    private function entferne(Appointment $termin, NotificationKind $art): void
    {
        AppointmentNotification::query()
            ->where('appointment_id', $termin->getKey())
            ->where('kind', $art->value)
            ->whereNull('sent_at')
            ->delete();
    }

    /** Je Mandant ueberschreibbar, Standard aus config/mrs.php. */
    private function vorlaufStunden(): int
    {
        $organisation = app(TenantContext::class)->current();

        $wert = $organisation instanceof Organization
            ? data_get($organisation->settings, 'reminders.hours_before')
            : null;

        return is_numeric($wert)
            ? (int) $wert
            : (int) config('mrs.reminders.hours_before', 24);
    }

    private function organisation(): Organization
    {
        $organisation = app(TenantContext::class)->current();

        if (! $organisation instanceof Organization) {
            app(TenantContext::class)->requireId(AppointmentNotification::class);
        }

        /** @var Organization $organisation */
        return $organisation;
    }
}
