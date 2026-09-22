<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\KalenderAboErneuern;
use App\Models\CalendarConnection;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Erneuert ablaufende Watch-Abonnements.
 *
 * R4: ein Abonnement laeuft nach hoechstens 30 Tagen ab, und nichts daran
 * meldet sich. Erneuert wird **deutlich** vor Ablauf
 * (`calendar.renew_before_expiry_hours`), nicht kurz davor -- faellt der Job
 * einmal aus, ist der Sync sonst tot, und ein toter Sync heisst Termine ueber
 * belegten Zeiten.
 *
 * Der Befehl stellt nur ein. Erneuert wird auf der Queue 'sync' (Regel 4).
 */
final class KalenderAbosErneuern extends Command
{
    protected $signature = 'mrs:kalender-abos-erneuern
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Erneuert ablaufende Kalender-Abonnements';

    public function handle(TenantContext $mandant): int
    {
        $jetzt = CarbonImmutable::now();
        $gesamt = 0;

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($organisation, $jetzt, &$gesamt): void {
                $faellig = CalendarConnection::query()
                    ->aktiv()
                    ->get()
                    ->filter(fn (CalendarConnection $v): bool => $v->brauchtErneuerung($jetzt));

                foreach ($faellig as $verbindung) {
                    KalenderAboErneuern::dispatch((string) $verbindung->uuid, (string) $organisation->uuid);
                }

                $gesamt += $faellig->count();

                if ($faellig->isNotEmpty()) {
                    $this->line(sprintf('%-30s %4d Abonnements', $organisation->name, $faellig->count()));
                }
            });
        }

        $this->info("Fertig: {$gesamt} Abonnements zur Erneuerung eingestellt.");

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Kalendererneuerung laeuft fuer alle Mandanten',
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
