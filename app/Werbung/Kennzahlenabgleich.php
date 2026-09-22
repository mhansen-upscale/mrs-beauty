<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Enums\ConnectionStatus;
use App\Enums\InsightLevel;
use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Support\Fehlereinordnung;
use App\Werbung\Meta\Insightsleser;
use Carbon\CarbonImmutable;

/**
 * Holt Metas Zahlen -- ueber ein nachlaufendes Fenster.
 *
 * **Gestern aendert sich noch.** Metas Zuordnungsfenster wirkt rueckwirkend:
 * die Zahlen eines Tages bewegen sich bis zu 28 Tage lang. Ein Abgleich, der
 * nur den Vortag holt, friert falsche Werte ein, und niemand bemerkt es --
 * die Zahl steht ja da.
 *
 * Jede Zeile wird deshalb ueberschrieben, nicht ergaenzt.
 */
final class Kennzahlenabgleich
{
    public function __construct(private readonly Insightsleser $leser) {}

    public function gleicheAb(AdAccount $konto, ?CarbonImmutable $jetzt = null): Kennzahlenbilanz
    {
        $jetzt ??= CarbonImmutable::now();
        $token = $konto->access_token;

        if (! is_string($token) || $token === '') {
            throw new Werbefehler(new Fehlereinordnung(
                'token_missing',
                wiederholen: false,
                zustand: ConnectionStatus::Expired,
            ));
        }

        $tage = max(1, (int) config('mrs.ads.insights_window_days'));
        $bis = $jetzt->toImmutable()->startOfDay();
        $von = $bis->subDays($tage - 1);

        $geschrieben = 0;
        $tageGesehen = [];

        foreach (InsightLevel::cases() as $ebene) {
            foreach ($this->leser->reihe($konto, $token, $ebene, $von, $bis) as $zahl) {
                $this->schreibe($konto, $zahl, $jetzt);

                $geschrieben++;
                $tageGesehen[$zahl->tag->toDateString()] = true;
            }
        }

        $konto->meldeErfolg($jetzt);

        return new Kennzahlenbilanz(
            zeilen: $geschrieben,
            tage: count($tageGesehen),
            von: $von,
            bis: $bis,
        );
    }

    /**
     * Ueberschreibend, nicht ergaenzend.
     *
     * updateOrCreate und nicht firstOrNew mit Vergleich: hier gibt es keine
     * verschluesselten Felder, und eine Zahl, die sich nicht geaendert hat,
     * kostet beim Schreiben nichts.
     */
    private function schreibe(AdAccount $konto, Tageszahl $zahl, CarbonImmutable $jetzt): void
    {
        AdInsight::query()->updateOrCreate(
            [
                'level' => $zahl->ebene->value,
                'external_id' => $zahl->kennung,
                'stat_date' => $zahl->tag->toDateString(),
            ],
            [
                'ad_account_id' => $konto->getKey(),
                'spend_minor' => $zahl->ausgabenMinor,
                'impressions' => $zahl->impressionen,
                'clicks' => $zahl->klicks,
                'link_clicks' => $zahl->linkklicks,
                'leads' => $zahl->leads,
                'synced_at' => $jetzt,
            ],
        );
    }
}
