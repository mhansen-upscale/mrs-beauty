<?php

declare(strict_types=1);

namespace App\Werbung\Verwaltung;

use App\Enums\SyncState;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\Location;
use App\Support\Fehlereinordnung;
use App\Werbung\Meta\Graphleser;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kampagnen anlegen und aendern -- lokal, mit Auftrag.
 *
 * **Nie im Anfragezyklus** (Entscheidung B2, Regel 4). Die Oberflaeche merkt
 * sich die Absicht und zeigt sie als *wird uebertragen*; ein Auftrag traegt
 * sie hinaus. Eine Aenderung, die noch unterwegs ist, sieht anders aus als
 * eine, die angekommen ist -- und anders als eine, die Meta abgelehnt hat.
 */
final class Kampagnenverwaltung
{
    public function __construct(
        private readonly Graphschreiber $schreiber,
        private readonly Graphleser $leser,
        private readonly Standortaufloesung $orte,
    ) {}

    /**
     * Legt die Kampagne lokal an. Uebertragen wird sie vom Auftrag.
     */
    public function plane(AdAccount $konto, Kampagnenplan $plan): AdCampaign
    {
        $merkmal = Kampagnenname::merkmal();
        $standort = Location::query()->whereUuid($plan->standort)->first();

        $kampagne = new AdCampaign;
        $kampagne->ad_account_id = $konto->getKey();

        // Noch keine Kennung von Meta: die kommt mit der Antwort. Bis dahin
        // steht das Merkmal fuer die Zeile.
        $kampagne->external_id = 'lokal-'.$merkmal;
        $kampagne->client_token = $merkmal;
        $kampagne->managed_by_us = true;
        $kampagne->name = Kampagnenname::fuer($plan->ziel, $plan->beginn, $standort, $merkmal, $plan->name);
        $kampagne->objective = $plan->ziel;
        $kampagne->status = 'PAUSED';
        $kampagne->effective_status = 'PAUSED';
        $kampagne->daily_budget = $plan->tagesbudgetMinor;
        $kampagne->starts_at = $plan->beginn;
        $kampagne->stops_at = $plan->ende;
        $kampagne->sync_state = SyncState::Pending;
        $kampagne->save();

        // Die Anzeigengruppe traegt die Zielgruppe. Sie entsteht mit, damit
        // die Praxis sieht, was sie angegeben hat -- auch bevor irgendetwas
        // bei Meta steht.
        $gruppe = new AdSet;
        $gruppe->ad_account_id = $konto->getKey();
        $gruppe->ad_campaign_id = $kampagne->getKey();
        $gruppe->external_id = 'lokal-'.$merkmal;
        $gruppe->client_token = $merkmal;
        $gruppe->managed_by_us = true;
        $gruppe->name = Kampagnenname::fuerGruppe($merkmal, $plan->gruppenname);
        $gruppe->status = 'PAUSED';
        $gruppe->effective_status = 'PAUSED';
        $gruppe->location_id = $standort?->getKey();
        $gruppe->radius_km = $plan->umkreisKm;
        $gruppe->age_min = $plan->altervon;
        $gruppe->age_max = $plan->alterbis;
        $gruppe->genders = $plan->geschlecht;
        $gruppe->starts_at = $plan->beginn;
        $gruppe->stops_at = $plan->ende;
        $gruppe->sync_state = SyncState::Pending;
        $gruppe->save();

        return $kampagne;
    }

    /**
     * Traegt eine geplante Kampagne zu Meta.
     *
     * **Erst nachsehen, dann anlegen.** Metas Marketing-API kennt keinen
     * Idempotenzschluessel; ein Auftrag, dessen Antwort verlorenging, legte
     * beim zweiten Versuch eine zweite Kampagne mit zweitem Budget an. Das
     * Merkmal im Namen macht sie wiederauffindbar.
     */
    public function uebertrage(AdCampaign $kampagne, AdAccount $konto): void
    {
        $token = (string) $konto->access_token;

        if ($kampagne->client_token === null) {
            return;
        }

        $vorhanden = $this->sucheKennung($konto, $token, $kampagne->client_token);

        $kennung = $vorhanden ?? $this->schreiber->lege($token, $konto->external_id.'/campaigns', [
            'name' => (string) $kampagne->name,
            'objective' => (string) $kampagne->objective,

            // **Pausiert angelegt.** Eine Kampagne, die im Moment des
            // Anlegens Geld ausgibt, laesst keinen Blick darauf zu, bevor
            // sie es tut.
            'status' => 'PAUSED',
            'special_ad_categories' => (string) json_encode([]),
            'daily_budget' => (string) $kampagne->daily_budget,

            // **Ausdruecklich ohne Gebotsbegrenzung.** Ohne diese Angabe
            // waehlt Meta eine Strategie, die an *jeder* Anzeigengruppe ein
            // `bid_amount` verlangt -- einen Wert, den dieses Produkt
            // nirgends erhebt und eine Praxis nicht sinnvoll setzen kann.
            // Jede Gruppe scheiterte daran, auch mit gueltigem Umkreis.
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
        ]);

        // Die Kennung sofort sichern: ein zweiter Lauf soll keine zweite
        // Kampagne anlegen, auch wenn der naechste Schritt scheitert.
        $kampagne->external_id = $kennung;
        $kampagne->save();

        $this->uebertrageGruppe($kampagne, $konto, $token);

        // **Fertig heisst: Kampagne und Gruppe.** Waere sie schon oben
        // "uebertragen", bliebe bei einem Abbruch dazwischen eine Kampagne
        // ohne Gruppe zurueck, die sich fuer erledigt haelt -- und die kein
        // Wiederanlauf mehr einholt, weil beide Schleifen `Pending` suchen.
        $kampagne->sync_state = SyncState::Synced;
        $kampagne->sync_error = null;
        $kampagne->synced_at = CarbonImmutable::now();
        $kampagne->save();
    }

    /**
     * Die Anzeigengruppe mit der Zielgruppe.
     *
     * **Ohne Ortskennung keine Gruppe.** Eine Anzeigengruppe ohne Umkreis
     * liefert deutschlandweit aus -- das Geld einer Praxis in Hamburg fiele
     * dann in Passau an. Lieber ein klarer Fehler als eine stille
     * Streuverlustkampagne.
     */
    private function uebertrageGruppe(AdCampaign $kampagne, AdAccount $konto, string $token): void
    {
        $gruppe = AdSet::query()
            ->where('ad_campaign_id', $kampagne->getKey())
            ->where('client_token', $kampagne->client_token)
            ->first();

        if (! $gruppe instanceof AdSet) {
            // Kein stiller Erfolg: eine Kampagne ohne Anzeigengruppe ist ein
            // Datenfehler. Frueher kehrte die Uebertragung hier wortlos
            // zurueck -- und jede Anzeige scheiterte danach an einer Gruppe,
            // die niemand vermisst hatte.
            throw new Werbefehler(new Fehlereinordnung(
                'no_adset',
                wiederholen: false,
                zustand: null,
                klartext: 'Zu dieser Kampagne gibt es keine Anzeigengruppe. Bitte legen Sie die Kampagne neu an.',
            ));
        }

        // Steht sie schon bei Meta, ist nichts zu tun.
        if (! str_starts_with($gruppe->external_id, 'lokal-')) {
            return;
        }

        $standort = $gruppe->location_id === null
            ? null
            : Location::query()->whereKey($gruppe->location_id)->first();

        $ortskennung = $standort instanceof Location ? $this->orte->kennung($standort, $token) : null;

        if ($ortskennung === null) {
            throw new Werbefehler(new Fehlereinordnung(
                'unknown_location',
                wiederholen: false,
                zustand: null,
                klartext: 'Meta kennt den Ort dieses Standorts nicht. Bitte die Ortsangabe im Standort prüfen.',
            ));
        }

        $kennung = $this->schreiber->lege($token, $konto->external_id.'/adsets', [
            'name' => (string) $gruppe->name,
            'campaign_id' => $kampagne->external_id,
            'status' => 'PAUSED',
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $kampagne->objective === 'OUTCOME_AWARENESS' ? 'REACH' : 'LEAD_GENERATION',

            // **Umkreis, Alter, Geschlecht -- und nichts sonst.**
            'targeting' => (string) json_encode([
                'geo_locations' => [
                    'cities' => [[
                        'key' => $ortskennung,
                        'radius' => $gruppe->radius_km,
                        'distance_unit' => 'kilometer',
                    ]],
                ],
                'age_min' => $gruppe->age_min,
                'age_max' => $gruppe->age_max,

                // **Metas Zielgruppenerweiterung aus.** Advantage Audience
                // liefert ueber die eingestellte Zielgruppe hinaus aus --
                // auch ueber die Altersuntergrenze. Bei aesthetischen
                // Behandlungen ist die keine Empfehlung, sondern eine
                // Grenze (Regel 6 und WP-30). Meta verlangt die Angabe
                // ausdruecklich: ohne sie legt es keine Gruppe mehr an.
                'targeting_automation' => ['advantage_audience' => 0],
            ] + ($gruppe->genders === null ? [] : [
                'genders' => [$gruppe->genders === 'weiblich' ? 2 : 1],
            ])),
        ]);

        $gruppe->external_id = $kennung;
        $gruppe->sync_state = SyncState::Synced;
        $gruppe->synced_at = CarbonImmutable::now();
        $gruppe->save();
    }

    /**
     * Zustand oder Budget aendern -- lokal sofort, dann uebertragen.
     *
     * Der Name fehlt hier mit Absicht: eine fremde Kampagne benennen wir
     * nicht um, und unsere erzeugt das Produkt.
     */
    public function passeAn(AdCampaign $kampagne, ?string $zustand = null, ?int $tagesbudgetMinor = null): AdCampaign
    {
        DB::transaction(function () use ($kampagne, $zustand, $tagesbudgetMinor): void {
            if ($zustand !== null) {
                $kampagne->status = $zustand;
                $kampagne->effective_status = $zustand;
            }

            if ($tagesbudgetMinor !== null) {
                $kampagne->daily_budget = $tagesbudgetMinor;
            }

            $kampagne->sync_state = SyncState::Pending;
            $kampagne->sync_error = null;
            $kampagne->save();
        });

        return $kampagne;
    }

    /**
     * Die Zielgruppe einer bestehenden Anzeigengruppe -- lokal sofort.
     *
     * **Sie gehoert zur Gruppe, nicht zur Kampagne.** Budget und Zustand
     * haengen an der Kampagne, Umkreis, Alter und Geschlecht an der Gruppe;
     * beides in einem Aufruf zu aendern hiesse, zwei Knoten bei Meta aus
     * einem Formular zu bedienen -- was die Oberflaeche trotzdem tut, weil
     * eine Praxis keine zwei Ebenen unterscheiden will.
     */
    public function passeZielgruppeAn(
        AdSet $gruppe,
        ?int $umkreisKm = null,
        ?int $altervon = null,
        ?int $alterbis = null,
        ?string $geschlecht = null,
        bool $geschlechtGesetzt = false,
    ): AdSet {
        DB::transaction(function () use ($gruppe, $umkreisKm, $altervon, $alterbis, $geschlecht, $geschlechtGesetzt): void {
            if ($umkreisKm !== null) {
                $gruppe->radius_km = $umkreisKm;
            }

            if ($altervon !== null) {
                $gruppe->age_min = $altervon;
            }

            if ($alterbis !== null) {
                $gruppe->age_max = $alterbis;
            }

            // Alle Geschlechter ist ein gueltiger Wert, nicht "nichts
            // angegeben" -- deshalb der eigene Schalter.
            if ($geschlechtGesetzt) {
                $gruppe->genders = $geschlecht;
            }

            $gruppe->sync_state = SyncState::Pending;
            $gruppe->sync_error = null;
            $gruppe->save();
        });

        return $gruppe;
    }

    public function uebertrageAenderung(AdCampaign $kampagne, AdAccount $konto): void
    {
        $daten = ['status' => (string) $kampagne->status];

        if ($kampagne->daily_budget !== null) {
            $daten['daily_budget'] = (string) $kampagne->daily_budget;
        }

        $this->schreiber->aendere((string) $konto->access_token, $kampagne->external_id, $daten);

        $kampagne->sync_state = SyncState::Synced;
        $kampagne->sync_error = null;
        $kampagne->synced_at = CarbonImmutable::now();
        $kampagne->save();

        $this->uebertrageZielgruppe($kampagne, $konto);
    }

    /**
     * Die geaenderte Zielgruppe hinterher.
     *
     * Nur, wenn sie wirklich aussteht -- und nur fuer eine Gruppe, die bei
     * Meta schon steht. Eine noch nicht uebertragene legt der Weg oben an.
     */
    private function uebertrageZielgruppe(AdCampaign $kampagne, AdAccount $konto): void
    {
        $gruppe = AdSet::query()
            ->where('ad_campaign_id', $kampagne->getKey())
            ->where('sync_state', SyncState::Pending)
            ->first();

        if (! $gruppe instanceof AdSet || str_starts_with($gruppe->external_id, 'lokal-')) {
            return;
        }

        $standort = $gruppe->location_id === null
            ? null
            : Location::query()->whereKey($gruppe->location_id)->first();

        $ortskennung = $standort instanceof Location
            ? $this->orte->kennung($standort, (string) $konto->access_token)
            : null;

        if ($ortskennung === null) {
            throw new Werbefehler(new Fehlereinordnung(
                'unknown_location',
                wiederholen: false,
                zustand: null,
                klartext: 'Meta kennt den Ort dieses Standorts nicht. Bitte die Ortsangabe im Standort prüfen.',
            ));
        }

        $this->schreiber->aendere((string) $konto->access_token, $gruppe->external_id, [
            'targeting' => (string) json_encode([
                'geo_locations' => [
                    'cities' => [[
                        'key' => $ortskennung,
                        'radius' => $gruppe->radius_km,
                        'distance_unit' => 'kilometer',
                    ]],
                ],
                'age_min' => $gruppe->age_min,
                'age_max' => $gruppe->age_max,
            ] + ($gruppe->genders === null ? [] : [
                'genders' => [$gruppe->genders === 'weiblich' ? 2 : 1],
            ])),
        ]);

        $gruppe->sync_state = SyncState::Synced;
        $gruppe->sync_error = null;
        $gruppe->synced_at = CarbonImmutable::now();
        $gruppe->save();
    }

    /**
     * Haelt einen Fehlschlag an der Kampagne fest -- im Klartext.
     *
     * **Ein Verbindungsfehler ist nicht die Schuld der Kampagne.** Bei
     * abgelaufenem Token oder fehlender Berechtigung bleibt die Aenderung
     * *wird uebertragen*: gewollt ist sie weiterhin, und sobald die
     * Verbindung steht, geht sie hinaus. Der Hinweis dazu haengt am
     * Werbekonto, wo er hingehoert -- ihn hier zu wiederholen hiesse, zweimal
     * dasselbe zu sagen, und beim zweiten Mal als Code.
     *
     * Gefunden im ersten Durchlauf gegen die echte Graph-API: an der
     * Kampagne stand "token_invalid", waehrend darueber schon "Der Zugang ist
     * abgelaufen" zu lesen war.
     */
    public function vermerkeFehler(AdCampaign $kampagne, Werbefehler $fehler): void
    {
        if ($fehler->einordnung->zustand !== null) {
            $kampagne->sync_state = SyncState::Pending;
            $kampagne->sync_error = null;
            $kampagne->save();

            return;
        }

        // **Wartend ist nicht abgelehnt.** Ein wiederholbarer Fehlschlag --
        // ein Rate Limit, eine unerreichbare Gegenstelle, eine
        // Anzeigengruppe, die noch nicht bei Meta steht -- sagt "spaeter
        // nochmal", nicht "nein". Als `Failed` gefuehrt waere er rot in der
        // Oberflaeche und, schlimmer, vom Wiederanlauf ausgenommen: der
        // sucht `Pending`.
        //
        // Der Grund bleibt trotzdem stehen. Er sagt, worauf gewartet wird.
        if ($fehler->einordnung->wiederholen) {
            $kampagne->sync_state = SyncState::Pending;
            $kampagne->sync_error = $fehler->einordnung->klartext ?? self::klartext($fehler->einordnung->kurzgrund);
            $kampagne->save();

            return;
        }

        $kampagne->sync_state = SyncState::Failed;
        $kampagne->sync_error = $fehler->einordnung->klartext ?? self::klartext($fehler->einordnung->kurzgrund);
        $kampagne->save();
    }

    /**
     * Deutsche Saetze fuer die Gruende, die keinen Klartext von Meta haben.
     *
     * **Kein Kurzgrund erreicht die Oberflaeche.** Die Praxis kann nur
     * beheben, was sie lesen kann -- und "no_id" ist kein Satz.
     */
    private static function klartext(string $kurzgrund): string
    {
        return match ($kurzgrund) {
            'rejected' => 'Meta hat die Kampagne abgelehnt, ohne einen Grund zu nennen.',
            'no_id' => 'Meta hat die Kampagne angelegt, aber keine Kennung zurückgegeben. Der nächste Versuch findet sie wieder.',
            'unreachable' => 'Meta war nicht erreichbar. Wir versuchen es erneut.',
            'rate_limit' => 'Meta hat zu viele Anfragen gemeldet. Wir versuchen es später erneut.',
            default => 'Die Übertragung ist fehlgeschlagen ('.$kurzgrund.').',
        };
    }

    /**
     * Sucht eine Kampagne ueber das Merkmal in ihrem Namen.
     */
    private function sucheKennung(AdAccount $konto, string $token, string $merkmal): ?string
    {
        $zeilen = $this->leser->sammle($token, $konto->external_id.'/campaigns', [
            'fields' => 'id,name',
        ]);

        foreach ($zeilen as $zeile) {
            if (Kampagnenname::merkmalAus(is_string($zeile['name'] ?? null) ? $zeile['name'] : null) === $merkmal) {
                $kennung = $zeile['id'] ?? null;

                return is_string($kennung) && $kennung !== '' ? $kennung : null;
            }
        }

        return null;
    }

    /**
     * Die Anzeigengruppen einer Kampagne -- fuer die Uebersicht.
     *
     * @return iterable<int, AdSet>
     */
    public function gruppen(AdCampaign $kampagne): iterable
    {
        return AdSet::query()->where('ad_campaign_id', $kampagne->getKey())->get();
    }
}
