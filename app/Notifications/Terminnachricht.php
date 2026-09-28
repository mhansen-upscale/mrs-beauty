<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Mailmarke;
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
use Throwable;

/**
 * Eine Nachricht zu einem Termin -- fuenf Anlaesse, ein Text je Anlass.
 *
 * **Die Betreffzeile nennt keine Behandlung.** Sie steht als Vorschau auf
 * einem Sperrbildschirm, den auch andere sehen. "Ihr Termin am 17. September"
 * statt "Erinnerung: Erstberatung Botox". Im Text steht sie -- dort ist sie
 * noetig, und dort hat sie der Empfaenger selbst gewaehlt.
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
 */
final class Terminnachricht extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    private readonly Mailmarke $marke;

    private readonly string $betreff;

    private readonly ?string $antwortAn;

    /** @var list<string> */
    private readonly array $zeilen;

    private readonly ?string $kalender;

    /** Die Zeile in appointment_notifications, fuer failed() -- kanonische UUID. */
    private readonly ?string $benachrichtigung;

    private readonly ?string $organisation;

    public function __construct(
        Appointment $termin,
        private readonly NotificationKind $art,
        private readonly string $praxisname,

        /**
         * Kopf und Fuss mit der Praxis (WP-07). Ohne Angabe nur ihr Name --
         * nie der des Produkts.
         */
        ?Mailmarke $marke = null,

        /** Die Zeile, die als fehlgeschlagen gilt, wenn der Versand scheitert. */
        ?AppointmentNotification $zeile = null,
    ) {
        $this->marke = $marke ?? new Mailmarke($praxisname);

        $standort = $termin->location;
        $beginn = $standort->ortszeit($termin->starts_at);

        $this->betreff = $this->betreff($beginn->translatedFormat('j. F'));

        // Wer auf eine Terminerinnerung antwortet, will die Praxis erreichen,
        // nicht uns.
        $this->antwortAn = is_string($standort->email) && $standort->email !== '' ? $standort->email : null;

        // Die Eckdaten stehen in jeder der fuenf Nachrichten gleich.
        $this->zeilen = [
            ...$this->einleitung(),
            '**'.$beginn->translatedFormat('l, j. F Y').', '
                .$beginn->format('H:i').'–'
                .$standort->ortszeit($termin->ends_at)->format('H:i').' Uhr**',
            $termin->appointmentType->name.' bei '.$termin->practitioner->name(),
            $this->anschrift($standort->name, $standort->street, $standort->postal_code, $standort->city),
            ...$this->schluss(),
        ];

        // Bestaetigung, Verschiebung und Absage tragen eine Kalenderdatei
        // (WP-14). Dieselbe UID ueber alle drei -- nur so ersetzt die
        // Verschiebung den Eintrag und die Absage entfernt ihn.
        $this->kalender = Termineinladung::gehoertDazu($art) ? Termineinladung::fuer($termin, $praxisname, $art) : null;

        // Der Auftrag laeuft ohne Mandanten; failed() braucht ihn zurueck.
        $this->benachrichtigung = $zeile?->uuid;
        $this->organisation = $zeile instanceof AppointmentNotification ? app(TenantContext::class)->current()?->uuid : null;

        $this->onQueue('default');

        // Der Auftrag entsteht nach dem Beanspruchen der Zeile; ein Arbeiter,
        // der schneller ist als ein Commit, faende sonst nichts.
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $nachricht = (new MailMessage)
            // **Kopf und Fuss tragen die Praxis**, nicht config('app.name')
            // (offen seit WP-13): das Geruest steht in mail.praxis.
            ->markdown('mail.praxis', ['marke' => $this->marke])
            // Die Mail kommt von der Praxis, nicht von uns. Die Adresse
            // bleibt unsere -- eine eigene Absenderdomain samt SPF und DKIM
            // gehoert zu WP-07 --, der Anzeigename ist der der Praxis.
            ->from((string) config('mail.from.address'), $this->praxisname)
            ->subject($this->betreff)
            ->greeting('Guten Tag,');

        if ($this->antwortAn !== null) {
            $nachricht->replyTo($this->antwortAn, $this->praxisname);
        }

        foreach ($this->zeilen as $zeile) {
            $nachricht->line($zeile);
        }

        $nachricht->salutation('Viele Grüße, '.$this->praxisname);

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
     */
    public function failed(Throwable $fehler): void
    {
        if ($this->benachrichtigung === null || $this->organisation === null) {
            return;
        }

        $praxis = Organization::query()->whereUuid($this->organisation)->first();

        if (! $praxis instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($praxis, function (): void {
            $zeile = AppointmentNotification::query()->whereUuid((string) $this->benachrichtigung)->first();

            if (! $zeile instanceof AppointmentNotification) {
                return;
            }

            $zeile->sent_at = null;
            $zeile->failed_at = CarbonImmutable::now();
            $zeile->failure = 'mail';
            $zeile->save();
        });
    }

    private function betreff(string $tag): string
    {
        return match ($this->art) {
            NotificationKind::Cancellation => "Ihr Termin am {$tag} entfällt",
            NotificationKind::Rescheduled => "Neuer Termin am {$tag}",
            default => "Ihr Termin am {$tag}",
        };
    }

    /**
     * @return list<string>
     */
    private function einleitung(): array
    {
        return match ($this->art) {
            NotificationKind::RequestReceived => [
                'vielen Dank für Ihre Anfrage. Wir haben sie erhalten und melden uns, sobald der Termin bestätigt ist.',
                'Ihre Anfrage:',
            ],
            NotificationKind::Confirmation => [
                'Ihr Termin ist bestätigt.',
            ],
            NotificationKind::Reminder => [
                'wir möchten Sie an Ihren Termin erinnern.',
            ],
            NotificationKind::Rescheduled => [
                'Ihr Termin wurde verschoben. Er findet jetzt zu dieser Zeit statt:',
            ],
            NotificationKind::Cancellation => [
                'Ihr Termin wurde abgesagt. Es handelt sich um diesen Termin:',
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function schluss(): array
    {
        return match ($this->art) {
            NotificationKind::Cancellation => [
                'Wenn Sie einen neuen Termin möchten, melden Sie sich gerne bei uns.',
            ],
            NotificationKind::RequestReceived => [],
            default => [
                'Sollten Sie den Termin nicht wahrnehmen können, sagen Sie uns bitte rechtzeitig Bescheid.',
            ],
        };
    }

    private function anschrift(string $name, ?string $strasse, ?string $plz, ?string $ort): string
    {
        $zeile = array_filter([$strasse, trim(($plz ?? '').' '.($ort ?? ''))]);

        return $zeile === [] ? $name : $name.', '.implode(', ', $zeile);
    }
}
