<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Enums\ConnectionStatus;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Support\Fehlereinordnung;
use App\Werbung\Meta\Graphleser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Holt Kampagnen, Anzeigengruppen und Anzeigen eines Werbekontos.
 *
 * **Gelesen wird, nicht geschrieben.** Kein Aufruf dieser Klasse aendert bei
 * Meta etwas; das gehoert zu WP-27 und zu einer Berechtigung, die wir noch
 * nicht haben.
 *
 * **Was bei Meta verschwindet, wird markiert, nicht geloescht.** Eine
 * geloeschte Kampagne bleibt in der Auswertung und ab WP-32 in der
 * Attribution sichtbar.
 */
final class Strukturabgleich
{
    private const KAMPAGNENFELDER = 'id,name,status,effective_status,objective,daily_budget,lifetime_budget,start_time,stop_time';

    private const GRUPPENFELDER = 'id,name,status,effective_status,optimization_goal,daily_budget,lifetime_budget,start_time,end_time,campaign_id';

    private const ANZEIGENFELDER = 'id,name,status,effective_status,adset_id,creative{id}';

    public function __construct(private readonly Graphleser $leser) {}

    public function gleicheAb(AdAccount $konto, ?CarbonImmutable $jetzt = null): Abgleichbilanz
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

        $bilanz = new Abgleichbilanz;

        $kampagnen = $this->kampagnen($konto, $token, $jetzt, $bilanz);
        $gruppen = $this->gruppen($konto, $token, $jetzt, $bilanz, $kampagnen);
        $this->anzeigen($konto, $token, $jetzt, $bilanz, $gruppen);

        $konto->meldeErfolg($jetzt);

        return $bilanz;
    }

    /**
     * @return array<string, AdCampaign>
     */
    private function kampagnen(AdAccount $konto, string $token, CarbonImmutable $jetzt, Abgleichbilanz &$bilanz): array
    {
        $zeilen = $this->leser->sammle($token, $konto->external_id.'/campaigns', [
            'fields' => self::KAMPAGNENFELDER,
        ]);

        /** @var Collection<int, AdCampaign> $vorhanden */
        $vorhanden = AdCampaign::query()->where('ad_account_id', $konto->getKey())->get();
        $nachKennung = $vorhanden->keyBy('external_id');
        $gesehen = [];

        foreach ($zeilen as $zeile) {
            $kennung = $this->text(data_get($zeile, 'id'));

            if ($kennung === null) {
                continue;
            }

            $gesehen[$kennung] = true;
            $modell = $nachKennung->get($kennung);
            $neu = ! $modell instanceof AdCampaign;

            if ($neu) {
                $modell = new AdCampaign(['external_id' => $kennung]);
                $modell->ad_account_id = $konto->getKey();
            }

            $veraendert = $this->uebernimm($modell, [
                'name' => $this->text(data_get($zeile, 'name')),
                'status' => $this->text(data_get($zeile, 'status')),
                'effective_status' => $this->text(data_get($zeile, 'effective_status')),
                'objective' => $this->text(data_get($zeile, 'objective')),
                'daily_budget' => $this->betrag(data_get($zeile, 'daily_budget')),
                'lifetime_budget' => $this->betrag(data_get($zeile, 'lifetime_budget')),
                'starts_at' => $this->zeit(data_get($zeile, 'start_time')),
                'stops_at' => $this->zeit(data_get($zeile, 'stop_time')),
                'vanished_at' => null,
            ]);

            $this->sichere($modell, $jetzt, $neu, $veraendert, $bilanz);

            $nachKennung->put($kennung, $modell);
        }

        $this->verschwundene($vorhanden, $gesehen, $jetzt, $bilanz);

        return $nachKennung->all();
    }

    /**
     * @param  array<string, AdCampaign>  $kampagnen
     * @return array<string, AdSet>
     */
    private function gruppen(AdAccount $konto, string $token, CarbonImmutable $jetzt, Abgleichbilanz &$bilanz, array $kampagnen): array
    {
        $zeilen = $this->leser->sammle($token, $konto->external_id.'/adsets', [
            'fields' => self::GRUPPENFELDER,
        ]);

        /** @var Collection<int, AdSet> $vorhanden */
        $vorhanden = AdSet::query()->where('ad_account_id', $konto->getKey())->get();
        $nachKennung = $vorhanden->keyBy('external_id');
        $gesehen = [];

        foreach ($zeilen as $zeile) {
            $kennung = $this->text(data_get($zeile, 'id'));
            $kampagne = $kampagnen[$this->text(data_get($zeile, 'campaign_id')) ?? ''] ?? null;

            // Eine Anzeigengruppe ohne ihre Kampagne waere ein Verweis ins
            // Leere -- der zusammengesetzte Fremdschluessel liesse sie ohnehin
            // nicht zu.
            if ($kennung === null || ! $kampagne instanceof AdCampaign) {
                continue;
            }

            $gesehen[$kennung] = true;
            $modell = $nachKennung->get($kennung);
            $neu = ! $modell instanceof AdSet;

            if ($neu) {
                $modell = new AdSet(['external_id' => $kennung]);
                $modell->ad_account_id = $konto->getKey();
            }

            $modell->ad_campaign_id = $kampagne->getKey();

            $veraendert = $this->uebernimm($modell, [
                'name' => $this->text(data_get($zeile, 'name')),
                'status' => $this->text(data_get($zeile, 'status')),
                'effective_status' => $this->text(data_get($zeile, 'effective_status')),
                'optimization_goal' => $this->text(data_get($zeile, 'optimization_goal')),
                'daily_budget' => $this->betrag(data_get($zeile, 'daily_budget')),
                'lifetime_budget' => $this->betrag(data_get($zeile, 'lifetime_budget')),
                'starts_at' => $this->zeit(data_get($zeile, 'start_time')),
                'stops_at' => $this->zeit(data_get($zeile, 'end_time')),
                'vanished_at' => null,
            ]);

            $this->sichere($modell, $jetzt, $neu, $veraendert, $bilanz);

            $nachKennung->put($kennung, $modell);
        }

        $this->verschwundene($vorhanden, $gesehen, $jetzt, $bilanz);

        return $nachKennung->all();
    }

    /**
     * @param  array<string, AdSet>  $gruppen
     */
    private function anzeigen(AdAccount $konto, string $token, CarbonImmutable $jetzt, Abgleichbilanz &$bilanz, array $gruppen): void
    {
        $zeilen = $this->leser->sammle($token, $konto->external_id.'/ads', [
            'fields' => self::ANZEIGENFELDER,
        ]);

        /** @var Collection<int, Ad> $vorhanden */
        $vorhanden = Ad::query()->where('ad_account_id', $konto->getKey())->get();
        $nachKennung = $vorhanden->keyBy('external_id');
        $gesehen = [];

        foreach ($zeilen as $zeile) {
            $kennung = $this->text(data_get($zeile, 'id'));
            $gruppe = $gruppen[$this->text(data_get($zeile, 'adset_id')) ?? ''] ?? null;

            if ($kennung === null || ! $gruppe instanceof AdSet) {
                continue;
            }

            $gesehen[$kennung] = true;
            $modell = $nachKennung->get($kennung);
            $neu = ! $modell instanceof Ad;

            if ($neu) {
                $modell = new Ad(['external_id' => $kennung]);
                $modell->ad_account_id = $konto->getKey();
            }

            $modell->ad_set_id = $gruppe->getKey();

            $veraendert = $this->uebernimm($modell, [
                'name' => $this->text(data_get($zeile, 'name')),
                'status' => $this->text(data_get($zeile, 'status')),
                'effective_status' => $this->text(data_get($zeile, 'effective_status')),
                'creative_external_id' => $this->text(data_get($zeile, 'creative.id')),
                'vanished_at' => null,
            ]);

            $this->sichere($modell, $jetzt, $neu, $veraendert, $bilanz);
        }

        $this->verschwundene($vorhanden, $gesehen, $jetzt, $bilanz);
    }

    /**
     * Uebernimmt nur, was sich wirklich geaendert hat.
     *
     * **Nicht getAttributes() oder isDirty() fragen.** Der Name traegt den
     * Encrypted-Cast, und der verschluesselt bei jedem Setzen mit einem neuen
     * Initialisierungsvektor -- derselbe Klartext ergibt jedes Mal ein anderes
     * Geheimnis. Eloquent haelt das fuer eine Aenderung, und dann meldete
     * jeder naechtliche Lauf jede Kampagne als geaendert, schriebe jede Zeile
     * neu und liesse die Bilanz nach Bewegung aussehen, wo keine war.
     *
     * Verglichen wird deshalb der gelesene Wert, nicht der gespeicherte.
     *
     * @param  array<string, mixed>  $werte
     */
    private function uebernimm(AdCampaign|AdSet|Ad $modell, array $werte): bool
    {
        $veraendert = false;

        foreach ($werte as $feld => $wert) {
            $bisher = $modell->getAttribute($feld);

            // Zeiten ueber den Zeitstempel vergleichen: zwei Objekte sind nie
            // identisch, auch wenn sie denselben Moment bezeichnen.
            $gleich = $bisher instanceof CarbonImmutable && $wert instanceof CarbonImmutable
                ? $bisher->equalTo($wert)
                : $bisher === $wert;

            if ($gleich) {
                continue;
            }

            $modell->setAttribute($feld, $wert);
            $veraendert = true;
        }

        return $veraendert;
    }

    private function sichere(AdCampaign|AdSet|Ad $modell, CarbonImmutable $jetzt, bool $neu, bool $veraendert, Abgleichbilanz &$bilanz): void
    {
        $modell->synced_at = $jetzt;
        $modell->save();

        $bilanz = $bilanz->plus(
            angelegt: $neu ? 1 : 0,
            geaendert: ! $neu && $veraendert ? 1 : 0,
            verschwunden: 0,
        );
    }

    /**
     * @param  iterable<AdCampaign|AdSet|Ad>  $vorhanden
     * @param  array<string, true>  $gesehen
     */
    private function verschwundene(iterable $vorhanden, array $gesehen, CarbonImmutable $jetzt, Abgleichbilanz &$bilanz): void
    {
        foreach ($vorhanden as $modell) {
            if (isset($gesehen[$modell->external_id]) || $modell->vanished_at !== null) {
                continue;
            }

            $modell->markiereVerschwunden($jetzt);

            $bilanz = $bilanz->plus(0, 0, 1);
        }
    }

    private function text(mixed $wert): ?string
    {
        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    /**
     * Betraege kommen als Zeichenkette in kleinster Einheit.
     *
     * Wer sie als Float liest, verliert Cent; wer sie als Euro liest, liegt um
     * den Faktor 100 daneben.
     */
    private function betrag(mixed $wert): ?int
    {
        return is_numeric($wert) ? (int) $wert : null;
    }

    /**
     * Metas Zeitstempel tragen einen Versatz: `2026-09-01T08:00:00+0200`.
     *
     * **Ohne Umrechnung landet die Ortszeit als UTC in der Spalte** -- der
     * Termin verschiebt sich um zwei Stunden, und weil der gelesene Wert dann
     * nie dem gesendeten gleicht, meldet jeder naechtliche Lauf dieselbe
     * Kampagne erneut als geaendert. Der Fehler faellt dadurch als
     * Zaehlfehler auf, nicht als Zeitfehler -- und ohne die Bilanz gar nicht.
     *
     * `CLAUDE.md`: gespeichert wird UTC, ausgewertet in der Ortszeit des
     * Standorts.
     */
    private function zeit(mixed $wert): ?CarbonImmutable
    {
        return is_string($wert) && $wert !== '' ? CarbonImmutable::parse($wert)->utc() : null;
    }
}
