<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\ZweiFaktor\ZweiterFaktor;
use Illuminate\Console\Command;

/**
 * Setzt den zweiten Faktor eines Kontos zurueck (WP-35).
 *
 * **Fuer die, die sonst niemand zuruecksetzen kann**: die letzte Inhaberin
 * einer Praxis und den letzten Super-Admin. Alle anderen setzt das Team
 * (`team.manage`) oder die Betreiberverwaltung zurueck. Die Konsole haengt an
 * keiner Anmeldung -- deshalb verlangt sie eine Begruendung, und die steht
 * im Protokoll. Handelnde ist dort "System".
 *
 * Wer das ausfuehrt, hat vorher geprueft, dass die Person die ist, die sie
 * zu sein behauptet. Das Runbook steht in docs/betrieb.md.
 */
final class ZweiFaktorZuruecksetzen extends Command
{
    protected $signature = 'mrs:zwei-faktor-zuruecksetzen
        {email : Die Adresse des Kontos}
        {--grund= : Warum, fuer das Protokoll -- etwa die Ticketnummer}';

    protected $description = 'Setzt den zweiten Faktor eines Kontos zurueck, mit Begruendung im Protokoll';

    public function handle(ZweiterFaktor $zweiterFaktor): int
    {
        $email = (string) $this->argument('email');
        $grund = trim((string) $this->option('grund'));

        if ($grund === '') {
            $this->error('Ohne Begründung nicht: --grund="Telefon verloren, Ticket 123"');

            return self::FAILURE;
        }

        // users ist kein TenantModel -- die Adresse ist eindeutig ueber alle
        // Praxen und den Betreiber.
        $konto = User::query()->where('email', $email)->first();

        if (! $konto instanceof User) {
            $this->error("Kein Konto mit der Adresse {$email}.");

            return self::FAILURE;
        }

        if (! $konto->hatZweiFaktor()) {
            $this->info("{$email} hat keinen zweiten Faktor. Nichts zu tun.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Den zweiten Faktor von {$email} zurücksetzen?")) {
            return self::FAILURE;
        }

        $zweiterFaktor->setzeZurueck($konto, $grund);

        $this->info("Zurückgesetzt. {$email} meldet sich jetzt nur mit Passwort an und kann den zweiten Faktor neu einrichten.");

        return self::SUCCESS;
    }
}
