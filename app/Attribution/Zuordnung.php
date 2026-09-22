<?php

declare(strict_types=1);

namespace App\Attribution;

use App\Enums\AttributionModel;
use App\Models\AttributionTouch;
use App\Models\Contact;
use Carbon\CarbonImmutable;

/**
 * Die vier Modelle -- zur Abfragezeit, nicht beim Schreiben.
 *
 * **Das Rueckblickfenster ist eine Aussage, keine Einstellung.** 28 Tage:
 * der Entscheidungsweg bei aesthetischen Eingriffen ist lang, und mit einem
 * kuerzeren Fenster wird systematisch zu wenig zugeordnet. Die Praxis haelt
 * ihre Werbung dann fuer schlechter, als sie ist -- deshalb steht der Wert
 * sichtbar im Dashboard.
 */
final class Zuordnung
{
    /**
     * Die Touches, die fuer einen Zeitpunkt zaehlen.
     *
     * @return list<AttributionTouch>
     */
    public function beruehrungen(Contact $kontakt, CarbonImmutable $stichtag): array
    {
        $fenster = $stichtag->subDays((int) config('mrs.attribution.lookback_days'));

        /** @var list<AttributionTouch> */
        return AttributionTouch::query()
            ->where('contact_id', $kontakt->getKey())
            ->where('occurred_at', '<=', $stichtag)
            // Ausserhalb des Fensters wird nicht zugeordnet (Testfall 5).
            ->where('occurred_at', '>=', $fenster)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->values()
            ->all();
    }

    /**
     * Der zugeordnete Touch nach einem Modell.
     *
     * `null` heisst **„Quelle unbekannt"**, nicht „Direktzugriff" (Testfall
     * 9). Der Unterschied ist der zwischen „wir wissen es nicht" und „es kam
     * von nirgendwo".
     *
     * Linear hat keinen einzelnen Touch -- dafuer gibt es anteile().
     */
    public function touch(Contact $kontakt, CarbonImmutable $stichtag, ?AttributionModel $modell = null): ?AttributionTouch
    {
        $modell ??= $this->vorgabe();
        $touches = $this->beruehrungen($kontakt, $stichtag);

        if ($touches === []) {
            return null;
        }

        return match ($modell) {
            AttributionModel::FirstTouch => $touches[0],
            AttributionModel::LastTouch => $touches[count($touches) - 1],
            AttributionModel::LastNonDirect => $this->letzterMitQuelle($touches),

            // Linear hat keinen einzelnen. Der letzte mit Quelle ist die
            // ehrlichste Antwort auf eine Frage, die das Modell nicht stellt.
            AttributionModel::Linear => $this->letzterMitQuelle($touches),
        };
    }

    /**
     * Die Anteile je Touch -- fuer Linear.
     *
     * @return array<string, float> Touch-UUID => Anteil
     */
    public function anteile(Contact $kontakt, CarbonImmutable $stichtag, ?AttributionModel $modell = null): array
    {
        $modell ??= $this->vorgabe();
        $touches = $this->beruehrungen($kontakt, $stichtag);

        if ($touches === []) {
            return [];
        }

        if ($modell !== AttributionModel::Linear) {
            $einer = $this->touch($kontakt, $stichtag, $modell);

            return $einer === null ? [] : [(string) $einer->uuid => 1.0];
        }

        $anteil = 1.0 / count($touches);
        $verteilt = [];

        foreach ($touches as $touch) {
            $verteilt[(string) $touch->uuid] = $anteil;
        }

        return $verteilt;
    }

    /**
     * Der Stand, der am Termin einfriert (Entscheidung D13).
     *
     * **Eine Kopie, kein Verweis.** Kampagnen werden umbenannt, pausiert und
     * geloescht; ein Verweis wuerde mit umbenannt, und die Auswertung eines
     * alten Termins waere nach einem halben Jahr wertlos.
     *
     * @return array<string, mixed>|null
     */
    public function standFuer(Contact $kontakt, CarbonImmutable $stichtag, ?AttributionModel $modell = null): ?array
    {
        $modell ??= $this->vorgabe();
        $touch = $this->touch($kontakt, $stichtag, $modell);

        if (! $touch instanceof AttributionTouch) {
            return null;
        }

        return [
            'modell' => $modell->value,
            'kampagne' => $touch->campaign_external_id,
            'anzeigengruppe' => $touch->adset_external_id,
            'anzeige' => $touch->ad_external_id,
            'utm_source' => $touch->utm_source,
            'utm_medium' => $touch->utm_medium,
            'utm_campaign' => $touch->utm_campaign,
            'utm_content' => $touch->utm_content,
            'klick' => $touch->click_id,
            'verweis' => $touch->referrer_host,
            'zeitpunkt' => $touch->occurred_at->toIso8601String(),
            'eingefroren_am' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * **Ein Direktaufruf ist keine Quelle, sondern das Fehlen einer.**
     *
     * @param  list<AttributionTouch>  $touches
     */
    private function letzterMitQuelle(array $touches): ?AttributionTouch
    {
        foreach (array_reverse($touches) as $touch) {
            if ($touch->hatQuelle()) {
                return $touch;
            }
        }

        return null;
    }

    private function vorgabe(): AttributionModel
    {
        return AttributionModel::tryFrom((string) config('mrs.attribution.default_model'))
            ?? AttributionModel::LastNonDirect;
    }
}
