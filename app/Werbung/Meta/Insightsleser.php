<?php

declare(strict_types=1);

namespace App\Werbung\Meta;

use App\Enums\InsightLevel;
use App\Models\AdAccount;
use App\Werbung\Tageszahl;
use Carbon\CarbonImmutable;

/**
 * Metas Zahlen als Tagesreihe.
 *
 * **`time_increment=1` ist Pflicht.** Ohne ihn liefert Meta eine Summe ueber
 * den Zeitraum, und die laesst sich nicht mehr auf Tage verteilen -- ein
 * Zeitraum, den jemand spaeter anders waehlt, waere dann nicht zu
 * beantworten.
 *
 * Gelesen wird ausschliesslich. Der Graphleser kann nichts anderes.
 */
final class Insightsleser
{
    private const FELDER = 'spend,impressions,clicks,inline_link_clicks,actions';

    public function __construct(private readonly Graphleser $leser) {}

    /**
     * @return list<Tageszahl>
     */
    public function reihe(
        AdAccount $konto,
        string $token,
        InsightLevel $ebene,
        CarbonImmutable $von,
        CarbonImmutable $bis,
    ): array {
        $zeilen = $this->leser->sammle($token, $konto->external_id.'/insights', [
            'level' => $ebene->value,
            'fields' => self::FELDER,
            'time_increment' => 1,
            'time_range' => (string) json_encode([
                'since' => $von->toDateString(),
                'until' => $bis->toDateString(),
            ]),
        ]);

        $zahlen = [];

        foreach ($zeilen as $zeile) {
            $kennung = $this->kennung($zeile, $ebene);
            $tag = $this->tag(data_get($zeile, 'date_start'));

            if ($kennung === null || ! $tag instanceof CarbonImmutable) {
                continue;
            }

            $zahlen[] = new Tageszahl(
                ebene: $ebene,
                kennung: $kennung,

                tag: $tag,

                ausgabenMinor: $this->ausgaben(data_get($zeile, 'spend')),
                impressionen: $this->zahl(data_get($zeile, 'impressions')),
                klicks: $this->zahl(data_get($zeile, 'clicks')),
                linkklicks: $this->zahl(data_get($zeile, 'inline_link_clicks')),
                leads: $this->leads(data_get($zeile, 'actions')),
            );
        }

        return $zahlen;
    }

    /**
     * **Ein Datum, keine Uhrzeit.** Insights-Tage laufen in der Zeitzone des
     * Werbekontos; die Umrechnung nach UTC, die WP-26 bei Laufzeiten braucht,
     * waere hier falsch.
     */
    private function tag(mixed $wert): ?CarbonImmutable
    {
        if (! is_string($wert) || $wert === '') {
            return null;
        }

        $tag = CarbonImmutable::createFromFormat('Y-m-d', $wert);

        return $tag instanceof CarbonImmutable ? $tag->startOfDay() : null;
    }

    /**
     * @param  array<string, mixed>  $zeile
     */
    private function kennung(array $zeile, InsightLevel $ebene): ?string
    {
        $feld = match ($ebene) {
            InsightLevel::Campaign => 'campaign_id',
            InsightLevel::AdSet => 'adset_id',
            InsightLevel::Ad => 'ad_id',
        };

        $wert = data_get($zeile, $feld);

        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    /**
     * Ausgaben kommen als Dezimalzeichenkette: "25.43".
     *
     * **Budgets kommen anders** -- die liefert Meta in der kleinsten Einheit
     * ("2500" = 25,00 EUR). Zwei Konventionen in einer API. Wer `spend` wie
     * ein Budget liest, zeigt hundertfache Kosten.
     *
     * Gerundet wird kaufmaennisch und erst am Ende: (float) 25.43 * 100 ist
     * 2542.9999..., und (int) darauf ergaebe 2542.
     */
    private function ausgaben(mixed $wert): int
    {
        if (! is_numeric($wert)) {
            return 0;
        }

        return (int) round(((float) $wert) * 100);
    }

    private function zahl(mixed $wert): int
    {
        return is_numeric($wert) ? (int) $wert : 0;
    }

    /**
     * Was **Meta** als Ergebnis zaehlt.
     *
     * Nicht dasselbe wie ein Lead im Produkt: hier steht ein
     * Formularabschluss bei Meta, dort eine Anfrage, die bei der Praxis
     * ankommt. Der Unterschied gehoert in die Oberflaeche, sonst erzeugt er
     * genau die Diskussion, die WP-32 gewinnen soll.
     */
    private function leads(mixed $aktionen): int
    {
        if (! is_array($aktionen)) {
            return 0;
        }

        $summe = 0;

        foreach ($aktionen as $aktion) {
            $art = data_get($aktion, 'action_type');

            if (in_array($art, ['lead', 'onsite_conversion.lead_grouped'], true)) {
                $summe += (int) (data_get($aktion, 'value') ?? 0);
            }
        }

        return $summe;
    }
}
