<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Kanaele\Kanalfehler;
use App\Kanaele\WhatsApp\Templateabgleich;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Holt die genehmigten WhatsApp-Templates je Mandant.
 *
 * Laeuft taeglich: eine Genehmigung kommt ohne Ankuendigung, und eine
 * Sperrung nach schlechter Qualitaetsbewertung erst recht. Wer den Stand
 * nicht nachhaelt, bietet in der Inbox Templates an, die WhatsApp nicht mehr
 * annimmt.
 */
final class WhatsAppTemplatesAbgleichen extends Command
{
    protected $signature = 'mrs:whatsapp-templates
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Gleicht die WhatsApp-Templates mit Meta ab';

    public function handle(TenantContext $mandant, Templateabgleich $abgleich): int
    {
        $angelegt = 0;
        $geaendert = 0;

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($organisation, $abgleich, &$angelegt, &$geaendert): void {
                $verbindung = ChannelConnection::query()
                    ->where('channel', ChannelType::WhatsApp->value)
                    ->sendebereit()
                    ->first();

                if (! $verbindung instanceof ChannelConnection) {
                    return;
                }

                try {
                    $ergebnis = $abgleich->gleicheAb($verbindung);
                } catch (Kanalfehler $fehler) {
                    // Der Zustand der Verbindung gehoert ins Produkt, nicht
                    // nur ins Log (Regel 4).
                    if ($fehler->einordnung->zustand !== null) {
                        $verbindung->meldeAusfall($fehler->einordnung->zustand, $fehler->einordnung->kurzgrund);
                    }

                    $this->warn($organisation->name.': '.$fehler->einordnung->kurzgrund);

                    return;
                }

                $angelegt += $ergebnis['angelegt'];
                $geaendert += $ergebnis['geaendert'];
            });
        }

        $this->info("Fertig: {$angelegt} neu, {$geaendert} geaendert.");

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'WhatsApp-Templates werden fuer alle Mandanten abgeglichen',
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
