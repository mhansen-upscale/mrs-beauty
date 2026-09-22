<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Anzeigen\Vorschlagslauf;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Der woechentliche Lauf der Anzeigenvorschlaege (WP-31).
 *
 * Montags: eine Praxis, die montags drei Entwuerfe vorfindet, hat die Woche,
 * um damit etwas zu tun.
 *
 * **Ein Fehler bei einer Praxis haelt die uebrigen nicht an** -- dieselbe
 * Ueberlegung wie beim Werbeabgleich in WP-26.
 */
final class AnzeigenVorschlagen extends Command
{
    protected $signature = 'mrs:anzeigen-vorschlagen
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Erzeugt die Anzeigenentwuerfe der Woche';

    public function handle(TenantContext $mandant): int
    {
        $jetzt = CarbonImmutable::now();
        $gesamt = 0;
        $uebersprungen = [];

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($jetzt, &$gesamt, &$uebersprungen): void {
                // Erst im Mandanten aufloesen: Brand Guide, Kontingent und
                // Pruefung haengen alle daran.
                $ergebnis = app(Vorschlagslauf::class)->fuerPraxis($jetzt);

                $gesamt += $ergebnis['angelegt'];

                if ($ergebnis['grund'] !== null) {
                    $uebersprungen[$ergebnis['grund']] = ($uebersprungen[$ergebnis['grund']] ?? 0) + 1;
                }
            });
        }

        $this->info("Fertig: {$gesamt} Entwuerfe.");

        foreach ($uebersprungen as $grund => $anzahl) {
            $this->line("  uebersprungen ({$grund}): {$anzahl}");
        }

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Anzeigenvorschlaege werden fuer alle Mandanten erzeugt',
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
