<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Versand;

use App\Contracts\Plattformmail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Der Kanal jeder Produktmail: der hinterlegte Server, sonst `.env` (B23).
 *
 * **Scheitert der hinterlegte Server, geht die Mail ueber `.env`**, und die
 * Stoerung steht an der Einstellung. Wer auf einen Anmeldecode oder einen
 * Passwortlink wartet, darf nicht an einem Mailserver haengen bleiben, den
 * gestern jemand umgestellt hat.
 *
 * Ein rotierter App-Schluessel ohne APP_PREVIOUS_KEYS macht das Passwort
 * unlesbar -- auch das ist eine Stoerung mit Rueckfall, kein Absturz.
 */
final class PlattformMailkanal extends Versandkanal
{
    public function __construct(
        Factory $mailer,
        Markdown $markdown,
        private readonly Plattformversand $versand,
    ) {
        parent::__construct($mailer, $markdown);
    }

    /**
     * @param  mixed  $notifiable
     */
    public function send($notifiable, Notification $notification): ?SentMessage
    {
        if (! $notification instanceof Plattformmail) {
            throw new LogicException($notification::class.' ist keine Plattformmail (A15).');
        }

        $nachricht = method_exists($notification, 'toMail') ? $notification->toMail($notifiable) : null;

        if (! $nachricht instanceof MailMessage || ! is_object($notifiable) || ! $this->hatEmpfaenger($notifiable, $notification)) {
            return null;
        }

        $einstellung = $this->versand->einstellung();

        if ($antwortAn = $this->versand->antwortAn($einstellung)) {
            $nachricht->replyTo($antwortAn);
        }

        if ($einstellung->serverGilt()) {
            try {
                $mailer = $this->versand->hinterlegterMailer($einstellung);

                $nachricht->from(...$this->versand->absender($einstellung, hinterlegt: true));

                return $this->sendeUeber($mailer, $notifiable, $notification, $nachricht);
            } catch (DecryptException) {
                $einstellung->meldeStoerung('entschluesselung');
            } catch (TransportExceptionInterface) {
                // Ohne Klartext des Servers: eine SMTP-Meldung traegt
                // regelmaessig die Adresse des Empfaengers.
                $einstellung->meldeStoerung('smtp_failed');
            }
        }

        $nachricht->from(...$this->versand->absender($einstellung, hinterlegt: false));

        return $this->sendeUeber($this->versand->rueckfall(), $notifiable, $notification, $nachricht);
    }
}
