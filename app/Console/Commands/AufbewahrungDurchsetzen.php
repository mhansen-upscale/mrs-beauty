<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Datenschutz\Aufbewahrung;
use App\Enums\RetentionSubject;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Setzt die Aufbewahrungsfristen durch (Entscheidung C7).
 *
 * **Vorschau ist die Vorgabe, nicht die Ausnahme.** Ein Lauf, der zu viel
 * loescht, ist nicht rueckholbar -- und die Fristen hat jemand von Hand
 * eingetragen. Wer scharf schalten will, sagt es ausdruecklich.
 */
final class AufbewahrungDurchsetzen extends Command
{
    protected $signature = 'mrs:aufbewahrung
        {--scharf : Wirklich loeschen. Ohne diese Angabe wird nur gezeigt, was geschehen wuerde}
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Setzt die Aufbewahrungsfristen durch (Vorschau, sofern nicht --scharf)';

    public function handle(TenantContext $mandant, Aufbewahrung $aufbewahrung): int
    {
        $vorschau = ! (bool) $this->option('scharf');
        $jetzt = CarbonImmutable::now();
        $gesamt = 0;

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($organisation, $aufbewahrung, $vorschau, $jetzt, &$gesamt): void {
                // Eine Praxis ohne eigene Fristen bekommt die Standardwerte
                // aus Entscheidung C7 -- wer nie etwas eingetragen hat, soll
                // nicht dauerhaft alles aufbewahren.
                $aufbewahrung->richteEin();

                $ergebnis = $aufbewahrung->lauf($vorschau, $jetzt);

                // Die Einmal-PINs (WP-34b) mit fester Frist, neben den
                // Fristen der Praxis.
                $pins = $aufbewahrung->supportPins($vorschau, $jetzt);

                if ($ergebnis->gesamt() + $pins === 0) {
                    return;
                }

                $this->line($organisation->name);

                foreach ($ergebnis->nachGegenstand() as $gegenstand => $anzahl) {
                    if ($anzahl > 0) {
                        $this->line(sprintf(
                            '  %-36s %5d',
                            RetentionSubject::from($gegenstand)->label(),
                            $anzahl,
                        ));
                    }
                }

                if ($pins > 0) {
                    $this->line(sprintf('  %-36s %5d', 'Einmal-PINs des Supports', $pins));
                }

                $gesamt += $ergebnis->gesamt() + $pins;
            });
        }

        // **Das Protokoll ohne Mandanten** (Querzugriffe, Anmeldungen der
        // Betreiber) gehoert keiner Praxis und lief deshalb bei keiner mit --
        // es blieb ewig (WP-34a, C7). Nicht, wenn nur eine Praxis gemeint ist.
        if (! is_string($this->option('organisation'))) {
            $ohneMandant = $aufbewahrung->protokollOhneMandant($vorschau, $jetzt);

            if ($ohneMandant > 0) {
                $this->line('Protokoll ohne Mandanten');
                $this->line(sprintf('  %-36s %5d', RetentionSubject::AuditLog->label(), $ohneMandant));
                $gesamt += $ohneMandant;
            }

            // Die Demo-Anfragen der Startseite (WP-38) -- ebenfalls ohne
            // Praxis, mit eigener Frist.
            $demoanfragen = $aufbewahrung->demoanfragen($vorschau, $jetzt);

            if ($demoanfragen > 0) {
                $this->line('Ohne Praxis');
                $this->line(sprintf('  %-36s %5d', 'Demo-Anfragen', $demoanfragen));
                $gesamt += $demoanfragen;
            }
        }

        $this->info($vorschau
            ? "Vorschau: {$gesamt} Datensaetze waeren betroffen. Mit --scharf ausfuehren."
            : "Fertig: {$gesamt} Datensaetze bearbeitet.");

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Aufbewahrungsfristen werden fuer alle Mandanten durchgesetzt',
            function (): iterable {
                // **Auch fuer gesperrte Praxen** (WP-34a). Regel 3: Fristen
                // gelten je Datenart, nicht je Vertragsstand -- eine Sperre
                // hielte die Daten sonst ueber jede Frist hinaus.
                $abfrage = Organization::query();

                if (is_string($this->option('organisation'))) {
                    $abfrage->whereUuid((string) $this->option('organisation'));
                }

                return $abfrage->get();
            }
        );
    }
}
