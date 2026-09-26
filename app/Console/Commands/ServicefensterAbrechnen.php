<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ServicefensterAbrechnen as Auftrag;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reiht je Praxis die Abrechnung der Antworten im Service-Fenster ein
 * (Entscheidung B14).
 *
 * **Zum Monatsersten, fuer den Vormonat.** Der Preis steht an jeder Antwort
 * schon fest; der Lauf fasst nur zusammen. Bei null Euro schickt er nichts an
 * Stripe und vermerkt den Monat trotzdem als erledigt.
 */
final class ServicefensterAbrechnen extends Command
{
    protected $signature = 'mrs:servicefenster-abrechnen
        {--monat= : Der abzurechnende Monat als Y-m, sonst der Vormonat}';

    protected $description = 'Rechnet die Antworten im WhatsApp-Service-Fenster des Vormonats ab';

    public function handle(TenantContext $mandant): int
    {
        $monat = is_string($this->option('monat'))
            ? (string) $this->option('monat')
            : CarbonImmutable::now()->startOfMonth()->subMonth()->format('Y-m');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monat) !== 1) {
            $this->error('Der Monat muss als Y-m angegeben werden, etwa 2026-10.');

            return self::INVALID;
        }

        /** @var Collection<int, Organization> $praxen */
        $praxen = $mandant->acrossTenants(
            'Die Antworten im Service-Fenster werden fuer alle Mandanten abgerechnet',
            fn () => Organization::query()->whereNull('suspended_at')->get(),
        );

        foreach ($praxen as $praxis) {
            Auftrag::dispatch((string) $praxis->uuid, $monat);
        }

        $this->info('Eingereiht: '.$praxen->count().' Praxen fuer '.$monat.'.');

        return self::SUCCESS;
    }
}
