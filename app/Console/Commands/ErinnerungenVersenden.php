<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Enums\NotificationKind;
use App\Jobs\TerminnachrichtVersenden;
use App\Models\AppointmentNotification;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Stellt faellige Erinnerungen in die Queue.
 *
 * Laeuft je Mandant -- ohne Mandantenkontext wirft jede Abfrage, und das ist
 * Absicht (WP-03).
 *
 * Der Befehl verschickt nichts selbst. Er entscheidet nur, was faellig ist;
 * den Versand macht der Job (Regel 4).
 */
final class ErinnerungenVersenden extends Command
{
    protected $signature = 'mrs:erinnerungen-versenden
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Stellt faellige Terminerinnerungen in die Queue';

    public function handle(TenantContext $mandant): int
    {
        $jetzt = CarbonImmutable::now();

        $organisationen = $mandant->acrossTenants(
            'Erinnerungsversand laeuft fuer alle Mandanten',
            function (): iterable {
                $abfrage = Organization::query()->whereNull('suspended_at');

                if (is_string($this->option('organisation'))) {
                    $abfrage->whereUuid((string) $this->option('organisation'));
                }

                return $abfrage->get();
            }
        );

        $gesamt = 0;

        foreach ($organisationen as $organisation) {
            $mandant->runAs($organisation, function () use ($organisation, $jetzt, &$gesamt): void {
                $faellige = AppointmentNotification::query()
                    ->faellig($jetzt)
                    ->where('kind', NotificationKind::Reminder->value)
                    ->with('appointment')
                    ->get()
                    ->filter(fn (AppointmentNotification $zeile): bool => $this->nochSinnvoll($zeile, $jetzt));

                foreach ($faellige as $zeile) {
                    TerminnachrichtVersenden::dispatch((string) $zeile->uuid, (string) $organisation->uuid);
                }

                $gesamt += $faellige->count();

                if ($faellige->isNotEmpty()) {
                    $this->line(sprintf('%-30s %4d Erinnerungen', $organisation->name, $faellige->count()));
                }
            });
        }

        $this->info("Fertig: {$gesamt} Erinnerungen eingestellt.");

        return self::SUCCESS;
    }

    /**
     * Zwei Gruende, eine faellige Erinnerung liegen zu lassen.
     *
     * Ein abgesagter Termin braucht keine -- das ist der peinlichste Fehler
     * dieser Gattung. Und eine ueberfaellige ist wertlos: stand der Job
     * (Ausfall, Deploy), soll er nicht an einen Termin erinnern, der vor zwei
     * Stunden war.
     */
    private function nochSinnvoll(AppointmentNotification $zeile, CarbonImmutable $jetzt): bool
    {
        $termin = $zeile->appointment;

        return $termin->status !== AppointmentStatus::Cancelled
            && $termin->starts_at->greaterThan($jetzt);
    }
}
