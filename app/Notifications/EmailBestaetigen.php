<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Benachrichtigung\Versand\PlattformMailkanal;
use App\Contracts\Plattformmail;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Die Bitte, die E-Mail-Adresse zu bestaetigen -- ueber die Warteschlange.
 *
 * Text und Layout bleiben die aus AppServiceProvider::configureMails(): der
 * Rueckruf haengt an VerifyEmail und gilt fuer diese Unterklasse mit. Der
 * signierte Link entsteht beim Versand, nicht beim Einreihen -- seine Frist
 * beginnt, wenn die Mail hinausgeht.
 *
 * `realtime`, weil jemand gerade ein Konto angelegt hat und wartet.
 *
 * **Verschluesselt, obwohl nichts Geheimes darin steht**: Der Empfaenger ist
 * ein User, und dessen Schluessel sind Rohbytes (A4) -- unverschluesselt
 * bricht json_encode() die Nutzlast.
 */
final class EmailBestaetigen extends VerifyEmail implements Plattformmail, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('realtime');
        $this->afterCommit();
    }

    /**
     * Ueber den Versand der Plattform (WP-37, A15) -- nicht ueber den
     * Standardmailer des Frameworks.
     *
     * @param  mixed  $notifiable
     * @return list<class-string>
     */
    public function via($notifiable): array
    {
        return [PlattformMailkanal::class];
    }
}
