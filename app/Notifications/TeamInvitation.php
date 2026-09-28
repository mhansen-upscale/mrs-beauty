<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Plattformmails;
use App\Benachrichtigung\Versand\PlattformMailkanal;
use App\Contracts\Plattformmail;
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
 *
 * Eine Mail des Produkts (WP-37): Text und Aussehen pflegt der Betreiber, es
 * verschickt der Plattformversand.
 */
final class TeamInvitation extends Notification implements Plattformmail, ShouldBeEncrypted, ShouldQueue
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
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [PlattformMailkanal::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(Plattformmails::class)->einladung(
            $this->organisationsname,
            $this->rolle,
            $this->frist,
            route('invitations.show', ['token' => $this->merkmal]),
        );
    }
}
