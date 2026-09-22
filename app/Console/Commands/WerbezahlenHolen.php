<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdAccount;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Kennzahlenabgleich;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Holt Metas Zahlen aller verbundenen Werbekonten.
 *
 * Taeglich, nach dem Strukturabgleich: eine Zeile ohne ihre Kampagne waere
 * eine Zahl ohne Namen.
 *
 * **Ueber ein nachlaufendes Fenster**, nicht ueber den Vortag. Metas
 * Zuordnungsfenster wirkt rueckwirkend; wer nur gestern holt, friert falsche
 * Werte ein.
 */
final class WerbezahlenHolen extends Command
{
    protected $signature = 'mrs:werbung-zahlen
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Holt Metas Kennzahlen ueber das nachlaufende Fenster';

    public function handle(TenantContext $mandant, Kennzahlenabgleich $abgleich): int
    {
        $jetzt = CarbonImmutable::now();
        $zeilen = 0;
        $gestoert = 0;

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($organisation, $abgleich, $jetzt, &$zeilen, &$gestoert): void {
                $konto = AdAccount::query()->whereNull('disconnected_at')->first();

                if (! $konto instanceof AdAccount || ! $konto->istVerbunden()) {
                    return;
                }

                try {
                    $bilanz = $abgleich->gleicheAb($konto, $jetzt);
                } catch (Werbefehler $fehler) {
                    if ($fehler->einordnung->zustand !== null) {
                        $konto->meldeAusfall($fehler->einordnung->zustand, $fehler->einordnung->kurzgrund);
                    }

                    $gestoert++;
                    $this->warn($organisation->name.': '.$fehler->einordnung->kurzgrund);

                    return;
                }

                $zeilen += $bilanz->zeilen;
            });
        }

        $this->info("Fertig: {$zeilen} Zeilen, {$gestoert} gestoert.");

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Werbekennzahlen werden fuer alle Mandanten geholt',
            function (): iterable {
                $abfrage = Organization::query()->whereNull('suspended_at');

                if (is_string($this->option('organisation'))) {
                    $abfrage->whereUuid((string) $this->option('organisation'));
                }

                return $abfrage->get();
            }
        );
    }
}
