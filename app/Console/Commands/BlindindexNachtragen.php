<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\UsesBlindIndexes;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Traegt blinde Indizes nach.
 *
 * **Ein neuer blinder Index macht bestehende Daten unauffindbar**, bis er
 * nachgetragen ist. Die Migration legt nur die Spalte an; gefuellt wird sie
 * vom saving-Haken, und der laeuft fuer eine Zeile, die niemand mehr
 * anfasst, nie.
 *
 * Das faellt nicht auf: kein Fehler, keine Meldung, nur eine Suche, die
 * nichts findet -- dieselbe Fehlerklasse, gegen die der Index selbst
 * antritt. In WP-16 ist genau das passiert, als `phone_bidx` dazukam.
 *
 * Der Lauf ist idempotent: er rechnet jeden Index neu und schreibt nur, wo
 * sich etwas aendert.
 */
final class BlindindexNachtragen extends Command
{
    protected $signature = 'mrs:blindindex-nachtragen
        {--organisation= : Nur diese Organisation, als UUID}
        {--trocken : Nur zeigen, was zu tun waere}';

    protected $description = 'Traegt blinde Indizes fuer bestehende Daten nach';

    public function handle(TenantContext $mandant): int
    {
        $modelle = $this->modelle();

        if ($modelle === []) {
            $this->warn('Kein Modell mit blinden Indizes gefunden.');

            return self::FAILURE;
        }

        $trocken = (bool) $this->option('trocken');
        $gesamt = 0;

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($organisation, $modelle, $trocken, &$gesamt): void {
                foreach ($modelle as $klasse) {
                    $geaendert = $this->trageNach($klasse, $trocken);
                    $gesamt += $geaendert;

                    if ($geaendert > 0) {
                        $this->line(sprintf('%-30s %-24s %4d Zeilen', $organisation->name, class_basename($klasse), $geaendert));
                    }
                }
            });
        }

        $this->info($trocken
            ? "Trockenlauf: {$gesamt} Zeilen haetten einen neuen Index bekommen."
            : "Fertig: {$gesamt} Zeilen nachgetragen.");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model&UsesBlindIndexes>  $klasse
     */
    private function trageNach(string $klasse, bool $trocken): int
    {
        $geaendert = 0;

        $klasse::query()->chunkById(200, function (Collection $zeilen) use ($trocken, &$geaendert): void {
            /** @var Model&UsesBlindIndexes $zeile */
            foreach ($zeilen as $zeile) {
                $offen = false;

                foreach ($zeile->blindIndexes() as $feld => $spalte) {
                    $erwartet = $zeile->blindIndexHash($feld);

                    if ($erwartet === $zeile->getAttribute($spalte)) {
                        continue;
                    }

                    $zeile->setAttribute($spalte, $erwartet);
                    $offen = true;
                }

                if (! $offen) {
                    continue;
                }

                $geaendert++;

                // saveQuietly: der Haken haette nichts mehr zu tun, und ein
                // Protokolleintrag je Zeile waere ein Protokoll, in dem man
                // nichts mehr findet (Entscheidung C5, sinngemaess).
                if (! $trocken) {
                    $zeile->saveQuietly();
                }
            }
        });

        return $geaendert;
    }

    /**
     * Jedes Modell, das blinde Indizes fuehrt.
     *
     * Ueber die Schnittstelle gefunden und nicht aufgezaehlt: WP-18 und WP-20
     * bringen weitere, und eine Liste hier waere die Stelle, die dann
     * vergessen wird.
     *
     * @return list<class-string<Model&UsesBlindIndexes>>
     */
    private function modelle(): array
    {
        $gefunden = [];

        foreach (Finder::create()->files()->name('*.php')->in(app_path('Models')) as $datei) {
            $klasse = 'App\\Models\\'.str_replace(
                [app_path('Models').DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR],
                ['', '', '\\'],
                $datei->getRealPath()
            );

            if (! class_exists($klasse)) {
                continue;
            }

            $spiegel = new ReflectionClass($klasse);

            if ($spiegel->isAbstract() || ! $spiegel->implementsInterface(UsesBlindIndexes::class)) {
                continue;
            }

            /** @var class-string<Model&UsesBlindIndexes> $klasse */
            $gefunden[] = $klasse;
        }

        sort($gefunden);

        return $gefunden;
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Blinde Indizes werden fuer alle Mandanten nachgetragen',
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
