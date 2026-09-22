<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Verfuegbarkeit\SlotErzeuger;
use App\Verfuegbarkeit\SlotHalter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Zieht die materialisierte Verfuegbarkeit rollierend nach.
 *
 * Laeuft je Mandant. Ohne Mandantenkontext wirft jede Abfrage -- das ist
 * Absicht (WP-03), also wird hier ausdruecklich durchgeschaltet.
 */
final class SlotsErzeugen extends Command
{
    protected $signature = 'mrs:slots-erzeugen
        {--tage= : Wie weit im Voraus, Vorgabe aus config/mrs.php}
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Erzeugt Terminslots aus den Arbeitszeiten und raeumt abgelaufene Holds auf';

    public function handle(SlotErzeuger $erzeuger, SlotHalter $halter, TenantContext $mandant): int
    {
        $tage = (int) ($this->option('tage') ?? config('mrs.booking.horizon_days', 90));
        $von = CarbonImmutable::now()->startOfDay();
        $bis = $von->addDays($tage);

        $organisationen = $mandant->acrossTenants(
            'Slot-Erzeugung laeuft fuer alle Mandanten',
            function (): iterable {
                $abfrage = Organization::query()->whereNull('suspended_at');

                if (is_string($this->option('organisation'))) {
                    $abfrage->whereUuid((string) $this->option('organisation'));
                }

                return $abfrage->get();
            }
        );

        $gesamt = ['angelegt' => 0, 'entfernt' => 0, 'holds' => 0];

        foreach ($organisationen as $organisation) {
            $mandant->runAs($organisation, function () use ($erzeuger, $halter, $von, $bis, &$gesamt, $organisation): void {
                $ergebnis = $erzeuger->erzeuge($von, $bis);
                $holds = $halter->raeumeAbgelaufeneAuf();

                $gesamt['angelegt'] += $ergebnis['angelegt'];
                $gesamt['entfernt'] += $ergebnis['entfernt'];
                $gesamt['holds'] += $holds;

                $this->line(sprintf(
                    '%-30s %6d angelegt, %6d entfernt, %3d Holds freigegeben',
                    $organisation->name,
                    $ergebnis['angelegt'],
                    $ergebnis['entfernt'],
                    $holds,
                ));
            });
        }

        $this->info(sprintf(
            'Fertig bis %s: %d angelegt, %d entfernt, %d Holds freigegeben.',
            $bis->toDateString(),
            $gesamt['angelegt'],
            $gesamt['entfernt'],
            $gesamt['holds'],
        ));

        return self::SUCCESS;
    }
}
