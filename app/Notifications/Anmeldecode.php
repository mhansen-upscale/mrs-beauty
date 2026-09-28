<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Plattformmails;
use App\Benachrichtigung\Versand\PlattformMailkanal;
use App\Contracts\Plattformmail;
use App\Models\User;
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
 * Im Rahmen des Produkts, nicht in dem der Praxis -- die Mail kommt vom
 * Produkt (WP-37). Der Code steht im festen Kern, nie in der Vorlage.
 */
final class Anmeldecode extends Notification implements Plattformmail, ShouldBeEncrypted, ShouldQueue
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
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [PlattformMailkanal::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(Plattformmails::class)->anmeldecode(
            $this->code,
            $this->minuten,
            $this->einrichtung,
            $notifiable instanceof User ? (string) $notifiable->name : '',
        );
    }
}
