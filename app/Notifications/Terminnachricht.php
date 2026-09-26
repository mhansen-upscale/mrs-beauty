<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Mailmarke;
use App\Enums\NotificationKind;
use App\Kalender\Termineinladung;
use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
 */
final class Terminnachricht extends Notification
{
    use Queueable;

    private readonly Mailmarke $marke;

    public function __construct(
        private readonly Appointment $termin,
        private readonly NotificationKind $art,
        private readonly string $praxisname,

        /**
         * Kopf und Fuss mit der Praxis (WP-07). Ohne Angabe nur ihr Name --
         * nie der des Produkts.
         */
        ?Mailmarke $marke = null,
    ) {
        $this->marke = $marke ?? new Mailmarke($praxisname);
        $this->onQueue('default');
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
        $standort = $this->termin->location;
        $beginn = $standort->ortszeit($this->termin->starts_at);

        $nachricht = (new MailMessage)
            // **Kopf und Fuss tragen die Praxis**, nicht config('app.name')
            // (offen seit WP-13): das Geruest steht in mail.praxis.
            ->markdown('mail.praxis', ['marke' => $this->marke])
            // Die Mail kommt von der Praxis, nicht von uns. Die Adresse
            // bleibt unsere -- eine eigene Absenderdomain samt SPF und DKIM
            // gehoert zu WP-07 --, der Anzeigename ist der der Praxis.
            ->from((string) config('mail.from.address'), $this->praxisname)
            ->subject($this->betreff($beginn->translatedFormat('j. F')))
            ->greeting('Guten Tag,');

        // Wer auf eine Terminerinnerung antwortet, will die Praxis erreichen,
        // nicht uns.
        if (is_string($standort->email) && $standort->email !== '') {
            $nachricht->replyTo($standort->email, $this->praxisname);
        }

        foreach ($this->einleitung() as $zeile) {
            $nachricht->line($zeile);
        }

        // Die Eckdaten stehen in jeder der fuenf Nachrichten gleich.
        $nachricht
            ->line('**'.$beginn->translatedFormat('l, j. F Y').', '
                .$beginn->format('H:i').'–'
                .$standort->ortszeit($this->termin->ends_at)->format('H:i').' Uhr**')
            ->line($this->termin->appointmentType->name.' bei '.$this->termin->practitioner->name())
            ->line($this->anschrift($standort->name, $standort->street, $standort->postal_code, $standort->city));

        foreach ($this->schluss() as $zeile) {
            $nachricht->line($zeile);
        }

        $nachricht->salutation('Viele Grüße, '.$this->praxisname);

        // Bestaetigung, Verschiebung und Absage tragen eine Kalenderdatei
        // (WP-14). Dieselbe UID ueber alle drei -- nur so ersetzt die
        // Verschiebung den Eintrag und die Absage entfernt ihn.
        if (Termineinladung::gehoertDazu($this->art)) {
            $nachricht->attachData(
                Termineinladung::fuer($this->termin, $this->praxisname, $this->art),
                'termin.ics',
                ['mime' => 'text/calendar; charset=UTF-8; method='.Termineinladung::methode($this->art)],
            );
        }

        return $nachricht;
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
