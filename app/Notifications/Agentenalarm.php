<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Plattformmails;
use App\Benachrichtigung\Versand\PlattformMailkanal;
use App\Contracts\Plattformmail;
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
final class Agentenalarm extends Notification implements Plattformmail, ShouldBeEncrypted, ShouldQueue
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
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [PlattformMailkanal::class];
    }

    /**
     * Der Satz, dass der Inhalt fehlt, ist Kern -- keine Vorlage nimmt ihn
     * heraus, und keine kann den Inhalt hineinschreiben (C17).
     */
    public function toMail(object $notifiable): MailMessage
    {
        return app(Plattformmails::class)->agentenalarm(
            $this->praxisname,
            $this->grund->label(),
            url(route('inbox.index', ['gespraech' => $this->gespraech], false)),
        );
    }
}
