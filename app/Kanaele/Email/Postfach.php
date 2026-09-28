<?php

declare(strict_types=1);

namespace App\Kanaele\Email;

use App\Benachrichtigung\Versand\KeinPraxispostfach;
use App\Benachrichtigung\Versand\Smtpzugang;
use App\Enums\ChannelType;
use App\Models\ChannelConnection;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Der Versandweg einer Praxis -- **ihr eigenes Postfach, und nur das** (B22).
 *
 * Bis zum 28.09.2026 fiel eine Praxis ohne Zugangsdaten auf den Versand der
 * Plattform zurueck: eine Mail mit ihrer Adresse im Absender aus unserer
 * Infrastruktur. Das besteht SPF und DKIM nur, wenn jemand die DNS-Eintraege
 * der Praxis gesetzt hat -- und es war ein Weg, auf dem unsere Adresse fuer
 * fremde Post haftete. Jetzt: ohne eigenes Postfach geht keine Mail an eine
 * Patientin hinaus, und das Produkt sagt es.
 */
final class Postfach
{
    /** Der Transport, ueber den jede Praxis sendet -- Tests tauschen ihn. */
    public const TRANSPORT = 'praxis_smtp';

    /**
     * @throws KeinPraxispostfach
     */
    public function mailer(ChannelConnection $verbindung): Mailer
    {
        if (! $verbindung->hatEigenesPostfach()) {
            throw new KeinPraxispostfach;
        }

        // Gebaut je Verbindung und nicht als benannter Mailer in der
        // Konfiguration: die Zugangsdaten gehoeren dem Mandanten und liegen
        // verschluesselt in seiner Zeile, nicht in einer Datei, die alle
        // Mandanten teilen. Ein benannter Mailer bliebe ausserdem im
        // Arbeiter haengen -- mit den Zugangsdaten der vorigen Praxis (A15).
        return Mail::build(Smtpzugang::ausVerbindung($verbindung)->konfiguration(self::TRANSPORT));
    }

    /** Die Mailverbindung des geltenden Mandanten -- es gibt hoechstens eine. */
    public function verbindung(): ?ChannelConnection
    {
        return ChannelConnection::query()
            ->where('channel', ChannelType::Email->value)
            ->first();
    }

    /** Kann der geltende Mandant Mails an Patientinnen verschicken? */
    public function versandbereit(): bool
    {
        return $this->verbindung()?->kannVersenden() === true;
    }
}
