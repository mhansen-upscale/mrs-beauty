<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Plattformmails;
use App\Benachrichtigung\Versand\PlattformMailkanal;
use App\Benachrichtigung\Vorlagen\Mailtext;
use App\Contracts\Plattformmail;
use App\Enums\Mailart;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Eine Produktmail zur Probe -- mit Beispielwerten, an die Person, die sie
 * bestellt hat (WP-37 AK 18).
 *
 * Ueber denselben Weg wie die echte Mail, damit die Probe zeigt, was ankommt:
 * den Plattformversand, mit Rueckfall auf `.env`. Der Betreff sagt, dass es
 * eine Probe ist; Links und Code sind Beispiele und fuehren nirgendwohin.
 */
final class Mailprobe extends Notification implements Plattformmail, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Mailart $art,
        private readonly ?Mailtext $entwurf = null,
    ) {
        $this->onQueue('default');
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
        $nachricht = app(Plattformmails::class)->beispiel($this->art, $this->entwurf);

        return $nachricht->subject('Probe: '.$nachricht->subject);
    }
}
