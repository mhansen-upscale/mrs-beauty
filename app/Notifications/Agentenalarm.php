<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\GuardrailHit;
use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Der Alarm bei einem Komplikationssignal (Entscheidung G4).
 *
 * **Ohne den Inhalt der Nachricht.** Eine Mail liegt in fremden Postfaechern
 * und auf Sperrbildschirmen; was jemand ueber seine Schwellung geschrieben
 * hat, gehoert dorthin nicht (Regel 3). Die Mail sagt, **dass** etwas
 * vorliegt und wo es steht -- gelesen wird es im Produkt.
 *
 * Dieselbe Ueberlegung wie bei der Terminnachricht, nur strenger: dort ist
 * die Behandlung im Text zulaessig, weil der Empfaenger sie selbst gewaehlt
 * hat. Hier hat er gar nichts gewaehlt.
 *
 * **Ein eigener Auftrag, nicht Teil des Agentenlaufs.** Scheitert der
 * Mailserver, soll nicht der Lauf scheitern und wiederholt werden -- der
 * Alarm wird wiederholt, sonst nichts.
 *
 * **Das Gespraech als Kennung, nicht als Modell.** Conversation ist ein
 * TenantModel, und ein Arbeiter hat keinen Mandanten. Der Link braucht nur
 * die UUID.
 *
 * **Verschluesselt, obwohl nichts Geheimes darin steht**: Die Empfaengerin
 * ist ein User, und dessen Schluessel sind Rohbytes (A4) -- unverschluesselt
 * bricht json_encode() die Nutzlast.
 */
final class Agentenalarm extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    private readonly string $gespraech;

    public function __construct(
        Conversation $gespraech,
        private readonly GuardrailHit $grund,
        private readonly string $praxisname,
    ) {
        $this->gespraech = (string) $gespraech->uuid;

        // Queue `realtime`: ein Alarm, der in einer Stunde ankommt, ist
        // keiner. Nach dem Commit, falls ein Aufrufer je eine Transaktion
        // oeffnet -- die Verbindung steht auf after_commit = false.
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
        return (new MailMessage)
            ->subject('Bitte im Posteingang nachsehen')
            ->greeting('Es liegt eine Nachricht vor, die jemand lesen sollte.')
            ->line('Der Assistent hat sie an Sie übergeben: '.$this->grund->label().'.')
            ->line('Der Inhalt steht **nicht** in dieser E-Mail — er gehört in kein Postfach und auf keinen Sperrbildschirm.')
            ->action('Im Posteingang öffnen', url(route('inbox.index', ['gespraech' => $this->gespraech], false)))
            ->line('Der Assistent hält sich aus diesem Gespräch heraus, bis jemand ihn wieder hereinlässt.')
            ->salutation('Viele Grüße von '.$this->praxisname);
    }
}
