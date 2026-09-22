<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Enums\InsightLevel;
use App\Models\AdInsight;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die einzige Stelle, die aus den Tageszeilen Zahlen macht.
 *
 * **Eine Zahl hat eine Quelle.** Summen kommen nie von Meta, sondern immer
 * aus denselben Zeilen, aus denen auch die Tagesansicht kommt -- sonst
 * weichen Gesamtwert und Aufschluesselung voneinander ab, und niemand kann
 * sagen, welche der beiden stimmt.
 */
final class Kennzahlen
{
    /**
     * Die Summe ueber alle Kampagnen eines Zeitraums.
     */
    public function gesamt(CarbonImmutable $von, CarbonImmutable $bis): Kennzahlensatz
    {
        return $this->summiere(
            AdInsight::query()->ebene(InsightLevel::Campaign)->zeitraum($von, $bis)
        );
    }

    /**
     * Je Kampagne, ueber Metas Kennung.
     *
     * @return array<string, Kennzahlensatz>
     */
    public function jeKampagne(CarbonImmutable $von, CarbonImmutable $bis): array
    {
        $zeilen = AdInsight::query()
            ->ebene(InsightLevel::Campaign)
            ->zeitraum($von, $bis)
            ->select('external_id')
            ->selectRaw('SUM(spend_minor) AS ausgaben')
            ->selectRaw('SUM(impressions) AS impressionen')
            ->selectRaw('SUM(clicks) AS klicks')
            ->selectRaw('SUM(link_clicks) AS linkklicks')
            ->selectRaw('SUM(leads) AS leads')
            ->selectRaw('COUNT(*) AS tage')
            ->groupBy('external_id')
            ->get();

        $satz = [];

        foreach ($zeilen as $zeile) {
            $satz[(string) $zeile->getAttributes()['external_id']] = new Kennzahlensatz(
                ausgabenMinor: (int) $zeile->getAttributes()['ausgaben'],
                impressionen: (int) $zeile->getAttributes()['impressionen'],
                klicks: (int) $zeile->getAttributes()['klicks'],
                linkklicks: (int) $zeile->getAttributes()['linkklicks'],
                leads: (int) $zeile->getAttributes()['leads'],
                tage: (int) $zeile->getAttributes()['tage'],
            );
        }

        return $satz;
    }

    /**
     * Die Tagesreihe der Summe -- fuer den Verlauf.
     *
     * @return list<array<string, int|string>>
     */
    public function verlauf(CarbonImmutable $von, CarbonImmutable $bis): array
    {
        $zeilen = AdInsight::query()
            ->ebene(InsightLevel::Campaign)
            ->zeitraum($von, $bis)
            ->select('stat_date')
            ->selectRaw('SUM(spend_minor) AS ausgaben')
            ->selectRaw('SUM(impressions) AS impressionen')
            ->selectRaw('SUM(clicks) AS klicks')
            ->selectRaw('SUM(leads) AS leads')
            ->groupBy('stat_date')
            ->orderBy('stat_date')
            ->get();

        $verlauf = [];

        foreach ($zeilen as $zeile) {
            $werte = $zeile->getAttributes();

            $verlauf[] = [
                'tag' => (string) $werte['stat_date'],
                'ausgaben' => (int) $werte['ausgaben'],
                'impressionen' => (int) $werte['impressionen'],
                'klicks' => (int) $werte['klicks'],
                'leads' => (int) $werte['leads'],
            ];
        }

        return $verlauf;
    }

    /**
     * @param  Builder<AdInsight>  $abfrage
     */
    private function summiere(Builder $abfrage): Kennzahlensatz
    {
        $zeile = $abfrage
            ->selectRaw('COALESCE(SUM(spend_minor), 0) AS ausgaben')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressionen')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS klicks')
            ->selectRaw('COALESCE(SUM(link_clicks), 0) AS linkklicks')
            ->selectRaw('COALESCE(SUM(leads), 0) AS leads')
            ->selectRaw('COUNT(DISTINCT stat_date) AS tage')
            ->first();

        if ($zeile === null) {
            return new Kennzahlensatz;
        }

        $werte = $zeile->getAttributes();

        return new Kennzahlensatz(
            ausgabenMinor: (int) $werte['ausgaben'],
            impressionen: (int) $werte['impressionen'],
            klicks: (int) $werte['klicks'],
            linkklicks: (int) $werte['linkklicks'],
            leads: (int) $werte['leads'],
            tage: (int) $werte['tage'],
        );
    }
}
