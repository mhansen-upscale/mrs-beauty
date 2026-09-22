<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Die Einladung ins Team.
 *
 * Das Merkmal kommt **nur hier** im Klartext vor: in der Datenbank steht sein
 * Hash. Wer die Einladung erneut verschickt, erzeugt ein neues Merkmal.
 */
final class TeamInvitation extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Invitation $invitation,
        private readonly string $merkmal,
        private readonly string $organisationsname,
    ) {
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
        $url = route('invitations.show', ['token' => $this->merkmal]);

        return (new MailMessage)
            ->subject("Einladung zu {$this->organisationsname}")
            ->greeting('Hallo,')
            ->line("Sie wurden zu {$this->organisationsname} eingeladen.")
            ->line('Ihre Rolle: '.$this->invitation->role->label().'.')
            ->action('Einladung annehmen', $url)
            ->line('Die Einladung gilt bis zum '
                .$this->invitation->expires_at->timezone(config('mrs.business_timezone'))->format('d.m.Y, H:i').' Uhr.')
            ->salutation('Viele Gruesse');
    }
}
