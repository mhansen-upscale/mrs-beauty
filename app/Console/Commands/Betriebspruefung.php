<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Betrieb\Betriebslage;
use Illuminate\Console\Command;

/**
 * Die Betriebslage fuer die Konsole -- und fuer eine Ueberwachung von aussen.
 *
 * **Der Exit-Code ist die eigentliche Ausgabe.** Eine Ueberwachung liest
 * keine Tabelle; sie liest 0 oder 1. Wer den Befehl in einen Cron haengt,
 * bekommt eine Mail, sobald etwas liegenbleibt.
 */
final class Betriebspruefung extends Command
{
    protected $signature = 'mrs:betrieb {--json : Als JSON ausgeben}';

    protected $description = 'Zeigt fehlgeschlagene Auftraege und liegengebliebene Ereignisse';

    public function handle(Betriebslage $lage): int
    {
        $zahlen = $lage->fuerInstallation();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($zahlen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Kennzahl', 'Wert'], [
                ['Fehlgeschlagene Auftraege', $zahlen['fehlgeschlageneAuftraege']],
                ['Juengster Fehlschlag', $zahlen['juengsterFehlschlag'] ?? '—'],
                ['Liegengebliebene Ereignisse', $zahlen['offeneEreignisse']],
                ['Stehende Warteschlangen', $zahlen['stehendeWarteschlangen'] === []
                    ? '—'
                    : implode(', ', $zahlen['stehendeWarteschlangen'])],
                ['Mandanten', $zahlen['mandanten']],
            ]);
        }

        // 1 heisst: jemand sollte hinsehen. Nicht "kaputt" -- das entscheidet
        // ein Mensch.
        return $lage->auffaellig() ? 1 : self::SUCCESS;
    }
}
