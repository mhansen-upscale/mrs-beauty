<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backoffice\Finanzmonat;
use App\Backoffice\Finanzuebersicht;
use App\Models\MonthlyClosing;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Friert Einnahmen und Kosten des Vormonats je Praxis ein (WP-34d, B19).
 *
 * **Warum ueberhaupt:** `subscriptions` kennt nur den Jetzt-Zustand. Ob eine
 * Praxis im Maerz pausiert war, laesst sich im Mai nicht mehr rechnen -- also
 * wird es am Monatsersten festgehalten, mit dem Zustand dieses Moments.
 *
 * **Idempotent:** Ein zweiter Lauf fuer denselben Monat aendert keine Zeile.
 * **Auch fuer gesperrte Praxen:** Eine gesperrte Praxis kann im Vormonat
 * noch gezahlt haben.
 */
final class Monatsabschluss extends Command
{
    protected $signature = 'mrs:monatsabschluss
        {--monat= : Der Monat als Y-m, sonst der Vormonat}';

    protected $description = 'Friert Einnahmen und Kosten des Vormonats je Praxis ein (Finanzuebersicht)';

    public function handle(TenantContext $mandant, Finanzuebersicht $finanzen): int
    {
        $jetzt = CarbonImmutable::now();
        $angabe = $this->option('monat');
        $monat = is_string($angabe) ? $angabe : $jetzt->startOfMonth()->subMonthNoOverflow()->format('Y-m');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monat) !== 1) {
            $this->error('Der Monat muss als Y-m angegeben werden, etwa 2026-10.');

            return self::INVALID;
        }

        $beginn = CarbonImmutable::createFromFormat('!Y-m', $monat, 'UTC');

        // Ein laufender Monat eingefroren waere ein halber, und der zweite
        // Lauf am Monatsersten aenderte ihn nicht mehr (idempotent).
        if (! $beginn instanceof CarbonImmutable || $beginn->greaterThanOrEqualTo($jetzt->startOfMonth())) {
            $this->error("{$monat} ist noch nicht abgeschlossen. Abgeschlossen wird nur ein vergangener Monat.");

            return self::FAILURE;
        }

        /** @var array{0: Collection<int, Organization>, 1: Finanzmonat} $gerechnet */
        $gerechnet = $mandant->acrossTenants(
            'Monatsabschluss der Finanzuebersicht fuer alle Praxen, auch gesperrte (WP-34d)',
            function () use ($finanzen, $beginn, $jetzt): array {
                $praxen = $finanzen->praxen();

                return [$praxen, $finanzen->rechne($praxen, $beginn, $jetzt)];
            },
        );

        [$praxen, $ergebnis] = $gerechnet;
        $neu = 0;

        // **Geschrieben wird im Mandanten**, ausserhalb des Querzugriffs:
        // runAs() darin schaltete den Scope nicht wieder ein.
        foreach ($praxen as $praxis) {
            $zeile = $ergebnis->praxis((string) $praxis->uuid);

            if ($zeile === null) {
                continue;
            }

            $neu += $mandant->runAs($praxis, fn (): bool => MonthlyClosing::query()->firstOrCreate(
                ['month' => $monat],
                [...$zeile->alsAbschluss(), 'closed_at' => $jetzt],
            )->wasRecentlyCreated) ? 1 : 0;
        }

        $this->info("Monatsabschluss {$monat}: {$neu} neu, ".($praxen->count() - $neu).' schon abgeschlossen.');

        return self::SUCCESS;
    }
}
