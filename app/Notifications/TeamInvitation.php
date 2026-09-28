<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Die Einladung ins Team.
 *
 * Das Merkmal kommt **nur hier** im Klartext vor: in der Datenbank steht sein
 * Hash. Wer die Einladung erneut verschickt, erzeugt ein neues Merkmal.
 *
 * **Ueber die Warteschlange, verschluesselt.** Das Merkmal oeffnet vierzehn
 * Tage lang eine Praxis; in Redis und in failed_jobs steht es deshalb nur
 * mit dem App-Schluessel verschluesselt (ShouldBeEncrypted).
 *
 * **Keine Einladung als Modell in der Nutzlast.** Invitation ist ein
 * TenantModel, und ein Arbeiter hat keinen Mandanten -- das Wiederherstellen
 * wuerfe. Was die Mail braucht, steht fest, bevor sie in die Schlange geht.
 */
final class TeamInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    private readonly string $rolle;

    private readonly string $frist;

    public function __construct(
        Invitation $invitation,
        #[\SensitiveParameter]
        private readonly string $merkmal,
        private readonly string $organisationsname,
    ) {
        $this->rolle = $invitation->role->label();
        $this->frist = $invitation->expires_at->timezone(config('mrs.business_timezone'))->format('d.m.Y, H:i');

        $this->onQueue('default');
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
        $url = route('invitations.show', ['token' => $this->merkmal]);

        return (new MailMessage)
            ->subject("Einladung zu {$this->organisationsname}")
            ->greeting('Hallo,')
            ->line("Sie wurden zu {$this->organisationsname} eingeladen.")
            ->line('Ihre Rolle: '.$this->rolle.'.')
            ->action('Einladung annehmen', $url)
            ->line('Die Einladung gilt bis zum '.$this->frist.' Uhr.')
            ->salutation('Viele Gruesse');
    }
}
