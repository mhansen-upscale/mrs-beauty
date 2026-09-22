<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\KalenderRueckabgleich;
use App\Models\CalendarConnection;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Gleicht alle aktiven Kalenderverbindungen ab.
 *
 * **Das Netz unter dem Webhook.** Eine Zustellung kann ausbleiben: ein
 * abgelaufener Kanal, eine verlorene Anfrage, ein Deploy zur falschen Minute.
 * R4 nennt stille Ausfaelle den Normalfall -- ein naechtlicher Lauf holt sie
 * ein, bevor am naechsten Morgen jemand ueber einer belegten Zeit bucht.
 *
 * Der Befehl stellt nur ein. Abgeglichen wird auf der Queue 'sync' (Regel 4).
 */
final class KalenderAbgleichen extends Command
{
    protected $signature = 'mrs:kalender-abgleichen
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Gleicht alle aktiven Kalenderverbindungen ab';

    public function handle(TenantContext $mandant): int
    {
        $gesamt = 0;

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($organisation, &$gesamt): void {
                $verbindungen = CalendarConnection::query()->aktiv()->get();

                foreach ($verbindungen as $verbindung) {
                    KalenderRueckabgleich::dispatch((string) $verbindung->uuid, (string) $organisation->uuid);
                }

                $gesamt += $verbindungen->count();

                if ($verbindungen->isNotEmpty()) {
                    $this->line(sprintf('%-30s %4d Verbindungen', $organisation->name, $verbindungen->count()));
                }
            });
        }

        $this->info("Fertig: {$gesamt} Abgleiche eingestellt.");

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Kalenderabgleich laeuft fuer alle Mandanten',
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
