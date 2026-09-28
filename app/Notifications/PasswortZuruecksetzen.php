<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Der Link zum Zuruecksetzen des Passworts -- ueber die Warteschlange.
 *
 * Die Mail des Frameworks geht sonst im Anfragezyklus hinaus. Text und Layout
 * bleiben die aus AppServiceProvider::configureMails(): der Rueckruf haengt
 * an ResetPassword und gilt fuer diese Unterklasse mit.
 *
 * **Verschluesselt**: Das Merkmal setzt eine Stunde lang ein neues Passwort.
 * In Redis und in failed_jobs steht es nur mit dem App-Schluessel
 * verschluesselt.
 *
 * `realtime`, weil jemand auf der Anmeldeseite auf diese Mail wartet.
 */
final class PasswortZuruecksetzen extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);

        $this->onQueue('realtime');
        $this->afterCommit();
    }
}
