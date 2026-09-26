<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Benachrichtigung\Mailmarke;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Organization;
use App\Notifications\Terminnachricht;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Verschickt eine geplante Terminnachricht -- genau einmal.
 *
 * **Der Job beansprucht seine Zeile, bevor er verschickt.** Ein bedingtes
 * UPDATE auf sent_at IS NULL; nur wer die Zeile bekommt, schickt. Zwei
 * gleichzeitige Laeufe erzeugen damit eine Mail, nicht zwei -- und ein Job,
 * der zweimal laeuft, ist nach einem Deploy der Normalfall.
 *
 * Der Mandant kommt aus der Nutzlast: ein Job laeuft ohne Anfrage und ohne
 * angemeldeten Benutzer, also ohne Mandantenkontext.
 */
final class TerminnachrichtVersenden implements ShouldQueue
{
    use Queueable;

    /**
     * Beide Angaben sind **kanonische UUIDs**, keine Rohbytes: der
     * Primaerschluessel ist BINARY(16) (Entscheidung A4) und bricht in einer
     * Job-Nutzlast json_encode().
     */
    public function __construct(
        private readonly string $benachrichtigung,
        private readonly string $organisation,
    ) {
        $this->onQueue('default');

        // **Erst nach dem Commit.** Der Auftrag entsteht innerhalb der
        // Transaktion des Terminplaners, und die Queue-Verbindung steht
        // projektweit auf after_commit = false: ein Arbeiter, der schneller
        // ist als der Commit, faende die Zeile nicht und die Mail bliebe
        // lautlos aus. Nachgetragen in WP-14, wo dieselbe Frage fuer die
        // Kalenderauftraege anstand.
        $this->afterCommit();
    }

    public function handle(): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($organisation, function () use ($organisation): void {
            $zeile = AppointmentNotification::query()
                ->whereUuid($this->benachrichtigung)
                ->with('appointment')
                ->first();

            if (! $zeile instanceof AppointmentNotification || ! $zeile->istOffen()) {
                return;
            }

            $termin = $this->geladen($zeile->appointment);
            $kontakt = $termin->contact;

            if (! is_string($kontakt->email) || $kontakt->email === '') {
                // Kein Kontaktweg ist eine Information, keine Ausnahme, die
                // man verschluckt: die Zeile bleibt stehen und ist in der
                // Terminansicht sichtbar.
                $zeile->failed_at = CarbonImmutable::now();
                $zeile->failure = 'no_channel';
                $zeile->save();

                return;
            }

            if (! $this->beanspruche($zeile)) {
                return;
            }

            Notification::route('mail', [$kontakt->email => $kontakt->name()])
                ->notify(new Terminnachricht($termin, $zeile->kind, $organisation->name, Mailmarke::fuer($organisation)));
        });
    }

    /**
     * Setzt sent_at, aber nur wenn es noch nicht gesetzt war.
     *
     * Der Rueckgabewert von update() ist die Zahl der betroffenen Zeilen --
     * der eigentliche Schiedsrichter. Genau eine Sitzung bekommt die 1.
     */
    private function beanspruche(AppointmentNotification $zeile): bool
    {
        $jetzt = CarbonImmutable::now()->format('Y-m-d H:i:s');

        $betroffen = DB::table('appointment_notifications')
            ->where('id', $zeile->getKey())
            ->whereNull('sent_at')
            ->update(['sent_at' => $jetzt, 'updated_at' => $jetzt]);

        return $betroffen === 1;
    }

    /** Strenge Modelle lassen kein Nachladen zu. */
    private function geladen(Appointment $termin): Appointment
    {
        return $termin->loadMissing(['appointmentType', 'practitioner', 'location', 'contact']);
    }
}
