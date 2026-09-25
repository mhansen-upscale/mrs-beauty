<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Was die Seite *Werbung* zeigt.
 *
 * Struktur aus WP-26, Zahlen aus WP-28 -- zusammengefuehrt ueber Metas
 * Kennung, nicht ueber einen Fremdschluessel: die Zahlen ueberleben eine
 * Kampagne, die bei Meta verschwindet.
 */
final class Werbeuebersicht
{
    public function __construct(
        private readonly Namenspruefung $namen,
        private readonly Kennzahlen $kennzahlen,
    ) {}

    /**
     * Der gewaehlte Zeitraum, auf die angebotenen Laengen begrenzt.
     *
     * @return array{tage: int, von: CarbonImmutable, bis: CarbonImmutable}
     */
    public function zeitraum(?int $tage, ?CarbonImmutable $jetzt = null): array
    {
        $erlaubt = array_map(intval(...), (array) config('mrs.ads.ranges'));
        $tage = in_array($tage, $erlaubt, true) ? $tage : (int) ($erlaubt[1] ?? 30);

        $bis = ($jetzt ?? CarbonImmutable::now())->startOfDay();

        return ['tage' => $tage, 'von' => $bis->subDays($tage - 1), 'bis' => $bis];
    }

    /**
     * Die Summe des Zeitraums.
     *
     * @return array<string, int|float|null>
     */
    public function summe(CarbonImmutable $von, CarbonImmutable $bis): array
    {
        return $this->kennzahlen->gesamt($von, $bis)->toArray();
    }

    /**
     * @return list<array<string, int|string>>
     */
    public function verlauf(CarbonImmutable $von, CarbonImmutable $bis): array
    {
        return $this->kennzahlen->verlauf($von, $bis);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function konto(): ?array
    {
        $konto = AdAccount::query()->orderByDesc('connected_at')->first();

        if (! $konto instanceof AdAccount) {
            return null;
        }

        return [
            'uuid' => $konto->uuid,
            'kennung' => $konto->external_id,
            'name' => $konto->name,
            'waehrung' => $konto->currency,
            'zeitzone' => $konto->timezone,
            'zustand' => $konto->status->value,
            'zustandText' => $konto->status->label(),
            'verbunden' => $konto->istVerbunden(),
            'grund' => $konto->last_error,
            'gestoertSeit' => $konto->failed_at?->toIso8601String(),
            'zuletztAbgeglichen' => $konto->last_synced_at?->toIso8601String(),

            // Ohne sie laesst sich keine Anzeige anlegen -- sie ist der
            // Absender (WP-27b).
            'seite' => $konto->page_external_id,
            'tokenLaeuftAb' => $konto->token_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Die Zielgruppe einer Anzeigengruppe -- zum Anzeigen und zum Aendern.
     *
     * @return array<string, mixed>|null
     */
    private function zielgruppe(?AdSet $gruppe): ?array
    {
        if (! $gruppe instanceof AdSet) {
            return null;
        }

        return [
            'umkreis' => $gruppe->radius_km,
            'altervon' => $gruppe->age_min,
            'alterbis' => $gruppe->age_max,
            'geschlecht' => $gruppe->genders,

            // **Der Grund gehoert dazu.** Eine wartende Gruppe mit vermerktem
            // Grund haengt an etwas anderem; eine ohne ist gerade unterwegs.
            // Die Oberflaeche dreht nur bei der zweiten ein Rad.
            'uebertragung' => $gruppe->sync_state->value,
            'uebertragungFehler' => $gruppe->sync_error,
        ];
    }

    /**
     * Die Kampagnen mit ihren Anzeigengruppen und Anzeigen -- gezaehlt.
     *
     * **Sortiert wird in PHP.** Der Name liegt verschluesselt, also kann die
     * Datenbank nicht danach ordnen. Bei einigen Dutzend Kampagnen je Praxis
     * ist das die billigere Haelfte des Handels (wie P8).
     *
     * @return list<array<string, mixed>>
     */
    public function kampagnen(CarbonImmutable $von, CarbonImmutable $bis): array
    {
        $kampagnen = AdCampaign::query()->get();
        $zahlen = $this->kennzahlen->jeKampagne($von, $bis);

        $gruppen = AdSet::query()->get()->groupBy(
            fn (AdSet $gruppe): string => $gruppe->getAttributes()['ad_campaign_id']
        );

        $anzeigen = Ad::query()->get()->groupBy(
            fn (Ad $anzeige): string => $anzeige->getAttributes()['ad_set_id']
        );

        /** @var list<array<string, mixed>> $zeilen */
        $zeilen = $kampagnen
            ->map(function (AdCampaign $kampagne) use ($gruppen, $anzeigen, $zahlen): array {
                /** @var Collection<int, AdSet> $eigene */
                $eigene = $gruppen->get($kampagne->getAttributes()['id']) ?? collect();

                $anzahlAnzeigen = $eigene->sum(
                    fn (AdSet $gruppe): int => ($anzeigen->get($gruppe->getAttributes()['id']) ?? collect())->count()
                );

                return [
                    'uuid' => $kampagne->uuid,
                    'kennung' => $kampagne->external_id,
                    'name' => $kampagne->name,
                    'zustand' => $kampagne->effective_status ?? $kampagne->status,
                    'ziel' => $kampagne->objective,
                    'tagesbudget' => $kampagne->daily_budget,
                    'laufzeitbudget' => $kampagne->lifetime_budget,
                    'beginn' => $kampagne->starts_at?->toIso8601String(),
                    'ende' => $kampagne->stops_at?->toIso8601String(),
                    'gruppen' => $eigene->count(),
                    'anzeigen' => (int) $anzahlAnzeigen,

                    // **Die Zielgruppe der ersten Gruppe.** Eine von uns
                    // angelegte Kampagne hat genau eine; bei einer aus Metas
                    // Bestand uebernommenen zeigen wir, was wir gelesen
                    // haben, und aendern nichts daran.
                    'zielgruppe' => $this->zielgruppe($eigene->first()),
                    'verschwunden' => $kampagne->vanished_at !== null,

                    // Beauftragt, aber noch nicht durch: die Zeile steht bis
                    // dahin sichtbar auf dem Weg hinaus.
                    'wirdEntfernt' => $kampagne->deleting_at !== null,

                    // Was die Praxis will und was bei Meta steht, sind zwei
                    // Dinge (WP-27).
                    'uebertragung' => $kampagne->sync_state->value,
                    'uebertragungText' => $kampagne->sync_state->label(),
                    'uebertragungFehler' => $kampagne->sync_error,
                    'eigene' => $kampagne->managed_by_us,

                    // Der Hinweis, nicht die Sperre: den Namen aendern wir
                    // nicht, er gehoert der Praxis (C9).
                    'katalogtreffer' => $this->namen->treffer($kampagne->name),

                    // **Keine Zeilen ist nicht dasselbe wie Nullen.** Eine
                    // Kampagne, die seit Wochen pausiert, hat keine Zahlen;
                    // eine, die lief und nichts erreichte, hat Nullen. Wer
                    // beides gleich anzeigt, laesst die Praxis raten.
                    'zahlen' => isset($zahlen[$kampagne->external_id])
                        ? $zahlen[$kampagne->external_id]->toArray()
                        : null,
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => ($a['verschwunden'] ? 1 : 0) <=> ($b['verschwunden'] ? 1 : 0),
                fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']),
            ])
            ->values()
            ->all();

        return $zeilen;
    }
}
