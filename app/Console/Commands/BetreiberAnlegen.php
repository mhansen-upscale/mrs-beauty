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
 */
final class BetreiberAnlegen extends Command
{
    protected $signature = 'mrs:betreiber
        {email : Die Adresse des Kontos}
        {--rolle=super_admin : super_admin, customer_success oder finanzen}
        {--name= : Der angezeigte Name; ohne Angabe der Teil vor dem @}';

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

        try {
            $konto = $konten->lege($email, (string) $name, $rolle);
        } catch (RuntimeException $fehler) {
            $this->error($fehler->getMessage());

            return self::FAILURE;
        }

        $this->info("{$konto->email} ist {$rolle->label()}. Der Link zum Setzen des Passworts ist unterwegs, sofern das Konto neu ist.");

        return self::SUCCESS;
    }
}
