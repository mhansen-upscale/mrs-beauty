<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Der Code per E-Mail (WP-35).
 *
 * **Ueber die Warteschlange `realtime`, verschluesselt** (28.09.2026: jede
 * Mail geht ueber die Warteschlange). Die Person wartet auf der Seite --
 * deshalb die schnelle Schlange, mit Wiederholungen, falls der Mailserver
 * kurz nicht will. In Redis und in failed_jobs steht der Code nur mit dem
 * App-Schluessel verschluesselt (ShouldBeEncrypted), nie im Klartext.
 *
 * Der Code steht nicht im Betreff: der erscheint in Benachrichtigungen auf
 * dem Sperrbildschirm, auch wenn das Telefon jemand anderes in der Hand hat.
 *
 * Im Produktlayout, nicht in dem der Praxis -- die Mail kommt vom Produkt.
 */
final class Anmeldecode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $code,
        private readonly int $minuten,
        private readonly bool $einrichtung,
    ) {
        $this->onQueue('realtime');
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
            ->subject($this->einrichtung ? 'Code zur Einrichtung des zweiten Faktors' : 'Ihr Anmeldecode')
            ->greeting('Guten Tag,')
            ->line($this->einrichtung
                ? 'mit diesem Code schalten Sie den zweiten Faktor per E-Mail ein:'
                : 'mit diesem Code schließen Sie Ihre Anmeldung ab:')
            ->line("**{$this->code}**")
            ->line("Der Code gilt {$this->minuten} Minuten und nur einmal.");

        return $nachricht
            ->line($this->einrichtung
                ? 'Haben Sie das nicht angefordert? Dann kennt jemand Ihr Passwort — bitte ändern Sie es.'
                : 'Haben Sie sich nicht gerade angemeldet? Dann kennt jemand Ihr Passwort — bitte ändern Sie es.')
            ->salutation('Viele Grüße von '.config('app.name'));
    }
}
