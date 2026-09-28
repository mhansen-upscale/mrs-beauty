<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backoffice\Betreiberkonten;
use App\Enums\OperatorRole;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Legt ein Betreiberkonto an -- oder gibt einem vorhandenen eine andere Rolle
 * (WP-34a).
 *
 * **Fuer den ersten Super-Admin einer Installation.** Danach geht das im
 * Backoffice unter Betreiber. Und fuer den Notfall, dass der letzte
 * Super-Admin sein Passwort verloren hat: die Konsole ist der eine Weg, der
 * nicht an einer Anmeldung haengt.
 *
 * **Ohne Mail** (Staging) setzt `--passwort-ausgeben` ein erzeugtes Passwort
 * und zeigt es einmal -- statt eines Links, der nie ankaeme. Keine
 * Rueckfrage: Laravel Cloud fuehrt Befehle ohne Terminal aus.
 */
final class BetreiberAnlegen extends Command
{
    protected $signature = 'mrs:betreiber
        {email : Die Adresse des Kontos}
        {--rolle=super_admin : super_admin, customer_success oder finanzen}
        {--name= : Der angezeigte Name; ohne Angabe der Teil vor dem @}
        {--passwort-ausgeben : Ein Passwort erzeugen und einmal zeigen, statt den Link per Mail zu schicken}';

    protected $description = 'Legt ein Betreiberkonto an und schickt den Link zum Setzen des Passworts';

    public function handle(Betreiberkonten $konten): int
    {
        $email = (string) $this->argument('email');
        $rolle = OperatorRole::tryFrom((string) $this->option('rolle'));

        if (! $rolle instanceof OperatorRole) {
            $this->error('Unbekannte Rolle. Erlaubt: '.implode(', ', array_map(fn (OperatorRole $r): string => $r->value, OperatorRole::cases())));

            return self::FAILURE;
        }

        $name = is_string($this->option('name')) && $this->option('name') !== ''
            ? (string) $this->option('name')
            : strstr($email, '@', true);

        $mitPasswort = (bool) $this->option('passwort-ausgeben');

        try {
            $konto = $konten->lege($email, (string) $name, $rolle, linkSenden: ! $mitPasswort);
        } catch (RuntimeException $fehler) {
            $this->error($fehler->getMessage());

            return self::FAILURE;
        }

        if (! $mitPasswort) {
            $this->info("{$konto->email} ist {$rolle->label()}. Der Link zum Setzen des Passworts ist unterwegs, sofern das Konto neu ist.");

            return self::SUCCESS;
        }

        $passwort = $konten->neuesPasswort($konto);

        $this->info("{$konto->email} ist {$rolle->label()}.");
        $this->line("Passwort: {$passwort}");
        $this->line('Es wird nur dieses eine Mal gezeigt. Nach der Anmeldung unter /backoffice/anmelden bitte unter Einstellungen → Passwort ändern.');

        if ($konto->deactivated_at !== null) {
            $this->warn('Das Konto ist deaktiviert und bleibt es. Reaktivieren kann es ein Super-Admin im Backoffice unter Betreiberkonten.');
        }

        return self::SUCCESS;
    }
}
