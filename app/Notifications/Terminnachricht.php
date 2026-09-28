<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Mailaufbau;
use App\Benachrichtigung\Mailmarke;
use App\Benachrichtigung\Termindaten;
use App\Benachrichtigung\Versand\KeinPraxispostfach;
use App\Benachrichtigung\Versand\PraxisMailkanal;
use App\Benachrichtigung\Vorlagen\Festblock;
use App\Benachrichtigung\Vorlagen\Mailinhalt;
use App\Benachrichtigung\Vorlagen\Mailtext;
use App\Benachrichtigung\Vorlagen\Mailvorlagen;
use App\Contracts\Praxismail;
use App\Enums\Mailart;
use App\Enums\NotificationKind;
use App\Kalender\Termineinladung;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use LogicException;
use Throwable;

/**
 * Eine Nachricht zu einem Termin -- fuenf Anlaesse, ein Text je Anlass.
 *
 * **Die Betreffzeile nennt keine Behandlung.** Sie steht als Vorschau auf
 * einem Sperrbildschirm, den auch andere sehen. "Ihr Termin am 17. September"
 * statt "Erinnerung: Erstberatung Botox". Im Text steht sie -- dort ist sie
 * noetig, und dort hat sie der Empfaenger selbst gewaehlt. Seit WP-36 schreibt
 * die Praxis den Text selbst; der Betreff wird beim Speichern und hier noch
 * einmal geprueft (C17).
 *
 * Dieselbe Ueberlegung wie R2 beim Kalendersync (neutraler Titel), nur eine
 * Ebene weiter aussen.
 *
 * **Ein eigener Auftrag mit eigenen Versuchen.** Bis hier verschickte
 * TerminnachrichtVersenden selbst -- und hatte die Zeile vorher schon
 * beansprucht. Scheiterte der Mailserver, lief der Auftrag erneut, fand die
 * Zeile verschickt vor und tat nichts: die Mail war weg, die Terminansicht
 * sagte "verschickt". Jetzt wiederholt die Warteschlange den Versand, und
 * wer endgueltig scheitert, steht als "fehlgeschlagen" an der Zeile.
 *
 * **Gebaut im Mandantenkontext, nicht beim Versand** (wie die Mailmarke).
 * Appointment ist ein TenantModel, und ein Arbeiter hat keinen Mandanten --
 * in der Nutzlast stehen deshalb nur fertige Zeilen. Die Nutzlast nennt
 * Behandlung und Behandlerin und ist deshalb verschluesselt (Regel 3).
 *
 * **Ueber das Postfach der Praxis, und nur darueber** (B22, A15): der Kanal
 * ist PraxisMailkanal.
 */
final class Terminnachricht extends Notification implements Praxismail, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    private readonly Mailmarke $marke;

    private readonly Mailinhalt $inhalt;

    private readonly Festblock $kern;

    private readonly ?string $antwortAn;

    private readonly ?string $kalender;

    /** Die Zeile in appointment_notifications, fuer failed() -- kanonische UUID. */
    private readonly ?string $benachrichtigung;

    private readonly string $organisation;

    public function __construct(
        Appointment|Termindaten $termin,
        private readonly NotificationKind $art,
        private readonly string $praxisname,

        /**
         * Kopf und Fuss mit der Praxis (WP-07). Ohne Angabe nur ihr Name --
         * nie der des Produkts.
         */
        ?Mailmarke $marke = null,

        /** Die Zeile, die als fehlgeschlagen gilt, wenn der Versand scheitert. */
        ?AppointmentNotification $zeile = null,

        /** Ein Entwurf statt der geltenden Vorlage -- fuer die Probemail (WP-36). */
        ?Mailtext $text = null,

        /** Vor den Betreff, etwa "Probe: ". */
        string $vorsatz = '',
    ) {
        $praxis = app(TenantContext::class)->current();

        if (! $praxis instanceof Organization) {
            throw new LogicException('Eine Terminnachricht entsteht im Mandantenkontext.');
        }

        $this->marke = $marke ?? new Mailmarke($praxisname);

        $daten = $termin instanceof Termindaten ? $termin : Termindaten::aus($termin, $art, $praxisname);
        $mailart = Mailart::fuerTermin($art);
        $vorlagen = app(Mailvorlagen::class);

        $inhalt = $vorlagen->setzeTerminmail($mailart, $text ?? $vorlagen->fuerPraxis($mailart), $daten->werte);

        $this->inhalt = $vorsatz === '' ? $inhalt : new Mailinhalt(
            $vorsatz.$inhalt->betreff,
            $inhalt->anrede,
            $inhalt->einleitung,
            $inhalt->schluss,
            $inhalt->gruss,
        );

        // Die Eckdaten stehen in jeder der fuenf Nachrichten gleich -- fester
        // Kern, den keine Vorlage aendert (C17).
        $this->kern = new Festblock(vorher: [$daten->zeitzeile, $daten->leistungzeile, $daten->ortzeile]);

        $this->antwortAn = $daten->antwortAn;
        $this->kalender = $daten->kalender;

        // Der Auftrag laeuft ohne Mandanten; Kanal und failed() brauchen ihn
        // zurueck.
        $this->benachrichtigung = $zeile?->uuid;
        $this->organisation = (string) $praxis->uuid;

        $this->onQueue('default');

        // Der Auftrag entsteht nach dem Beanspruchen der Zeile; ein Arbeiter,
        // der schneller ist als ein Commit, faende sonst nichts.
        $this->afterCommit();
    }

    public function praxis(): string
    {
        return $this->organisation;
    }

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [PraxisMailkanal::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // **Kopf und Fuss tragen die Praxis**, nicht config('app.name'). Den
        // Absender setzt der Kanal: Adresse und Anzeigename ihres Postfachs.
        $nachricht = Mailaufbau::baue($this->marke, $this->inhalt, $this->kern);

        if ($this->antwortAn !== null) {
            $nachricht->replyTo($this->antwortAn, $this->praxisname);
        }

        if ($this->kalender !== null) {
            $nachricht->attachData(
                $this->kalender,
                'termin.ics',
                ['mime' => 'text/calendar; charset=UTF-8; method='.Termineinladung::methode($this->art)],
            );
        }

        return $nachricht;
    }

    /**
     * Nach dem letzten Versuch: die Zeile gilt als fehlgeschlagen, nicht als
     * verschickt. Die Terminansicht zeigt es (Regel 4: ein Ausfall erzeugt
     * einen Hinweis im Produkt, nicht nur im Log).
     *
     * Ohne Postfach heisst der Grund `no_mailer` (B22) -- das ist etwas
     * anderes als ein Server, der nicht antwortet.
     */
    public function failed(Throwable $fehler): void
    {
        if ($this->benachrichtigung === null) {
            return;
        }

        $praxis = Organization::query()->whereUuid($this->organisation)->first();

        if (! $praxis instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($praxis, function () use ($fehler): void {
            $zeile = AppointmentNotification::query()->whereUuid((string) $this->benachrichtigung)->first();

            if (! $zeile instanceof AppointmentNotification) {
                return;
            }

            $zeile->sent_at = null;
            $zeile->failed_at = CarbonImmutable::now();
            $zeile->failure = $fehler instanceof KeinPraxispostfach ? KeinPraxispostfach::GRUND : 'mail';
            $zeile->save();
        });
    }
}
