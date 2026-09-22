<?php

declare(strict_types=1);

namespace App\Kanaele\Email;

use App\Models\ChannelConnection;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Der Versandweg einer Praxis.
 *
 * **Das eigene Postfach, wenn es hinterlegt ist -- sonst der Versand der
 * Plattform.** Der Unterschied ist die Zustellbarkeit: eine Mail mit der
 * Adresse der Praxis im Absender, die aus unserer Infrastruktur kommt,
 * besteht SPF und DKIM nur, wenn jemand die DNS-Eintraege der Praxis
 * entsprechend gesetzt hat. Geht sie ueber das Postfach der Praxis, ist sie
 * eine Mail wie jede andere von dort.
 *
 * Der Rueckfall ist trotzdem kein Notbehelf: er haelt eine Praxis
 * arbeitsfaehig, die gerade erst anfaengt und noch keine Zugangsdaten
 * eingetragen hat.
 */
final class Postfach
{
    public function mailer(ChannelConnection $verbindung): Mailer
    {
        if (! $verbindung->hatEigenesPostfach()) {
            return Mail::mailer();
        }

        // Gebaut je Verbindung und nicht als benannter Mailer in der
        // Konfiguration: die Zugangsdaten gehoeren dem Mandanten und liegen
        // verschluesselt in seiner Zeile, nicht in einer Datei, die alle
        // Mandanten teilen.
        return Mail::build([
            'transport' => 'smtp',
            'host' => (string) $verbindung->smtp_host,
            'port' => (int) $verbindung->smtp_port,

            // 'tls' meint bei Symfony implizites TLS auf Port 465; auf 587
            // wird ohnehin STARTTLS ausgehandelt.
            'encryption' => $verbindung->smtp_encryption,
            'username' => $verbindung->smtp_username,
            'password' => $verbindung->smtp_password,
            'timeout' => 15,
        ]);
    }
}
