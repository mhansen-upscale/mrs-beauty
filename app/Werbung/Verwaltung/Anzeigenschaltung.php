<?php

declare(strict_types=1);

namespace App\Werbung\Verwaltung;

use App\Datenschutz\Anhangabgelehnt;
use App\Datenschutz\Anhangspeicher;
use App\Enums\Bildformat;
use App\Enums\SyncState;
use App\Enums\Vorschlagsstatus;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\AdSuggestion;
use App\Models\Attachment;
use App\Models\Organization;
use App\Support\Fehlereinordnung;
use App\Tenancy\TenantContext;
use App\Werbung\Meta\Graphleser;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Aus einem freigegebenen Entwurf wird eine Anzeige.
 *
 * **Der letzte Meter.** Alles davor -- Text, Grafik, HWG-Pruefung, Freigabe
 * -- steht in WP-30 und WP-31. Hier geht es nur noch hinaus, und zwar
 * pausiert: eine Anzeige, die im Moment des Anlegens ausliefert, laesst
 * keinen Blick darauf zu, bevor sie es tut.
 *
 * **Nie im Anfragezyklus** (Regel 4). Die Oberflaeche merkt sich die Absicht,
 * ein Auftrag traegt sie hinaus.
 *
 * **Regel 2 trennt Angebot und Person, nicht Wort und Wort** (C9): der
 * *Inhalt* der Anzeige darf die beworbene Leistung benennen -- dafuer wirbt
 * die Praxis. Der *Name* darf es nicht: er liegt unverschluesselt, wird von
 * Metas Oberflaeche ueberall angezeigt und friert am Termin als
 * attribution_snapshot ein.
 */
final class Anzeigenschaltung
{
    public function __construct(
        private readonly Graphschreiber $schreiber,
        private readonly Graphleser $leser,
        private readonly Anhangspeicher $anhaenge,
        private readonly TenantContext $mandant,
    ) {}

    /**
     * Legt die Anzeige lokal an. Uebertragen wird sie vom Auftrag.
     */
    public function plane(AdSet $gruppe, AdSuggestion $vorschlag): Ad
    {
        $merkmal = Kampagnenname::merkmal();

        $anzeige = new Ad;
        $anzeige->ad_account_id = $gruppe->ad_account_id;
        $anzeige->ad_set_id = $gruppe->getKey();
        $anzeige->ad_suggestion_id = $vorschlag->getKey();

        // Noch keine Kennung von Meta: die kommt mit der Antwort.
        $anzeige->external_id = 'lokal-'.$merkmal;
        $anzeige->client_token = $merkmal;
        $anzeige->managed_by_us = true;

        // **Der Name traegt nichts als das Merkmal.** Die Ueberschrift des
        // Entwurfs steht im Inhalt, wo sie hingehoert.
        $anzeige->name = 'Anzeige ['.$merkmal.']';
        $anzeige->status = 'PAUSED';
        $anzeige->effective_status = 'PAUSED';
        $anzeige->sync_state = SyncState::Pending;
        $anzeige->save();

        return $anzeige;
    }

    /**
     * Traegt eine geplante Anzeige zu Meta.
     *
     * Drei Schritte, in dieser Reihenfolge: Bilder hochladen, Creative anlegen,
     * Anzeige anlegen. Jeder braucht das Ergebnis des vorigen.
     *
     * **Erst nachsehen, dann anlegen.** Metas Marketing-API kennt keinen
     * Idempotenzschluessel; ein Auftrag, dessen Antwort verlorenging, legte
     * beim zweiten Versuch eine zweite Anzeige an -- und die kostet Geld.
     * Das Merkmal im Namen macht sie wiederauffindbar.
     */
    public function uebertrage(Ad $anzeige, AdAccount $konto): void
    {
        $token = (string) $konto->access_token;

        if ($anzeige->client_token === null) {
            return;
        }

        $vorhanden = $this->sucheKennung($konto, $token, $anzeige->client_token);

        if ($vorhanden !== null) {
            $anzeige->external_id = $vorhanden;
            $this->vermerkeErfolg($anzeige);

            return;
        }

        $vorschlag = $anzeige->vorschlag()->first();

        if (! $vorschlag instanceof AdSuggestion) {
            throw new Werbefehler(new Fehlereinordnung(
                'no_suggestion',
                wiederholen: false,
                zustand: null,
                klartext: 'Zu dieser Anzeige gibt es keinen Entwurf mehr.',
            ));
        }

        // **Die Bilder werden beim Uebertragen gelesen, nicht beim Schalten.**
        // Eine neue Grafik nach dem Schalten nimmt dem Entwurf die Freigabe
        // (WP-31) -- ungeprueft darf sie nicht hinaus, auch nicht ueber
        // "Erneut uebertragen" (WP-31b).
        if ($vorschlag->status !== Vorschlagsstatus::Freigegeben) {
            throw new Werbefehler(new Fehlereinordnung(
                'suggestion_not_approved',
                wiederholen: false,
                zustand: null,
                klartext: 'Der Entwurf zu dieser Anzeige ist nicht mehr freigegeben — etwa weil eine neue Grafik entstanden ist. Bitte prüfen, freigeben und erneut übertragen.',
            ));
        }

        $this->ladeBilderHoch($anzeige, $vorschlag, $konto, $token);

        $creative = $anzeige->creative_external_id ?? $this->legeCreative($anzeige, $vorschlag, $konto, $token);

        $anzeige->creative_external_id = $creative;
        $anzeige->save();

        $anzeige->external_id = $this->schreiber->lege($token, $konto->external_id.'/ads', [
            'name' => (string) $anzeige->name,
            'adset_id' => $this->gruppenkennung($anzeige),
            'creative' => (string) json_encode(['creative_id' => $creative]),

            // **Pausiert angelegt**, wie die Kampagne.
            'status' => 'PAUSED',
        ]);

        $this->vermerkeErfolg($anzeige);
    }

    /**
     * Zustand aendern -- lokal sofort, dann uebertragen.
     */
    public function passeAn(Ad $anzeige, string $zustand): Ad
    {
        $anzeige->status = $zustand;
        $anzeige->effective_status = $zustand;
        $anzeige->sync_state = SyncState::Pending;
        $anzeige->sync_error = null;
        $anzeige->save();

        return $anzeige;
    }

    public function uebertrageAenderung(Ad $anzeige, AdAccount $konto): void
    {
        $this->schreiber->aendere(
            (string) $konto->access_token,
            $anzeige->external_id,
            ['status' => (string) $anzeige->status],
        );

        $this->vermerkeErfolg($anzeige);
    }

    /**
     * Haelt einen Fehlschlag an der Anzeige fest -- im Klartext.
     *
     * Dieselbe Unterscheidung wie bei der Kampagne: ein Verbindungsfehler ist
     * nicht die Schuld der Anzeige, und der Hinweis dazu haengt am
     * Werbekonto.
     */
    public function vermerkeFehler(Ad $anzeige, Werbefehler $fehler): void
    {
        if ($fehler->einordnung->zustand !== null) {
            $anzeige->sync_state = SyncState::Pending;
            $anzeige->sync_error = null;
            $anzeige->save();

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
            $anzeige->sync_state = SyncState::Pending;
            $anzeige->sync_error = $fehler->einordnung->klartext ?? self::klartext($fehler->einordnung->kurzgrund);
            $anzeige->save();

            return;
        }

        $anzeige->sync_state = SyncState::Failed;
        $anzeige->sync_error = $fehler->einordnung->klartext ?? self::klartext($fehler->einordnung->kurzgrund);
        $anzeige->save();
    }

    /**
     * Laedt jedes Format hoch, das Meta noch nicht hat (WP-31b).
     *
     * **Geschaltet wird nur ein vollstaendiger Satz** (C13). Die Pruefung beim
     * Schalten reicht nicht: zwischen Schalten und Uebertragen kann eine
     * Grafik verschwinden -- und eine Anzeige mit zwei von drei Formaten
     * liefert Meta trotzdem aus, mit einem Quadrat in der Story.
     *
     * **Die Kennung gehoert zur Datei, nicht zum Format.** Eine neue Grafik
     * ist ein neues Bild; mit der Kennung der alten ginge das alte hinaus.
     * Hat sich eine Kennung geaendert, entsteht auch ein neues Creative.
     *
     * Nach jedem Format wird gespeichert: ein Lauf, der beim dritten
     * abbricht, laedt beim naechsten nur noch das dritte hoch.
     */
    private function ladeBilderHoch(Ad $anzeige, AdSuggestion $vorschlag, AdAccount $konto, string $token): void
    {
        $bilder = $vorschlag->bilder();
        $fehlend = $vorschlag->fehlendeFormate();

        if ($bilder === []) {
            throw new Werbefehler(new Fehlereinordnung(
                'no_image',
                wiederholen: false,
                zustand: null,
                klartext: 'Zu diesem Entwurf gibt es keine Grafik mehr.',
            ));
        }

        if ($fehlend !== []) {
            throw new Werbefehler(new Fehlereinordnung(
                'missing_formats',
                wiederholen: false,
                zustand: null,
                klartext: 'Zu dieser Anzeige fehlen Formate: '.self::formatliste($fehlend).'. Bitte erzeugen Sie die Grafik neu.',
            ));
        }

        $kennungen = $anzeige->image_hashes ?? [];

        foreach ($bilder as $format => $anhang) {
            if (isset($kennungen[$format]) && $kennungen[$format]['anhang'] === $anhang->uuid) {
                continue;
            }

            $kennungen[$format] = ['anhang' => (string) $anhang->uuid, 'hash' => $this->ladeBildHoch($anhang, $konto, $token)];

            $anzeige->image_hashes = $kennungen;
            $anzeige->creative_external_id = null;
            $anzeige->save();
        }
    }

    /**
     * Laedt eine Grafik hoch und gibt Metas Bildkennung zurueck.
     *
     * Das Bild liegt bei uns (C10) und geht als Base64 hinaus -- Metas
     * `/adimages` nimmt es so entgegen, ohne Multipart.
     */
    private function ladeBildHoch(Attachment $anhang, AdAccount $konto, string $token): string
    {
        // **Der Datensatz kann da sein und die Datei fort.** Dann wirft der
        // Anhangspeicher, nicht Meta -- und der Auftrag lief bis zum
        // 24.09.2026 in den Standardfall: "Die Ursache liegt bei uns". Das
        // stimmt zwar, hilft aber nicht. Hier kann die Praxis etwas tun.
        try {
            $rohinhalt = $this->anhaenge->rohinhalt($anhang);
        } catch (Anhangabgelehnt) {
            throw new Werbefehler(new Fehlereinordnung(
                'no_image_file',
                wiederholen: false,
                zustand: null,
                klartext: 'Die Grafik dieser Anzeige liegt nicht mehr im Speicher. Bitte erzeugen Sie sie neu.',
            ));
        }

        $antwort = $this->schreiber->legeRoh($token, $konto->external_id.'/adimages', [
            'bytes' => base64_encode($rohinhalt),
        ]);

        $bilder = data_get($antwort, 'images');
        $erstes = is_array($bilder) ? reset($bilder) : null;
        $hash = is_array($erstes) ? data_get($erstes, 'hash') : null;

        if (! is_string($hash) || $hash === '') {
            throw new Werbefehler(new Fehlereinordnung('no_image_hash', wiederholen: false, zustand: null));
        }

        return $hash;
    }

    /**
     * Das Creative: Bilder, Text, Ueberschrift, Schaltflaeche und das Ziel.
     *
     * **Hier darf die Leistung stehen** (C9). Das Ziel ist die eigene
     * Buchungsseite -- eine fremde Zielseite koennte alles behaupten, und
     * geprueft haben wir nur unsere.
     *
     * **Eine Anzeige, Formate je Platzierung** (WP-31b, C13). Nicht drei
     * Anzeigen: die stuenden mit drei Auslieferungen im Wettbewerb
     * gegeneinander, und die Kennzahlen zerfielen in drei Teile. Meta sieht
     * dafuer `asset_feed_spec` mit Regeln vor, welche Platzierung welches
     * Bild zeigt (Placement Asset Customization). Text und Ziel stehen darin
     * je einmal; die Seite bleibt im `object_story_spec` der Absender.
     */
    private function legeCreative(Ad $anzeige, AdSuggestion $vorschlag, AdAccount $konto, string $token): string
    {
        $seite = $konto->page_external_id;

        if ($seite === null || $seite === '') {
            throw new Werbefehler(new Fehlereinordnung(
                'no_page',
                wiederholen: false,
                zustand: null,
                klartext: 'Diesem Werbekonto ist keine Facebook-Seite zugeordnet. Ohne sie kann Meta keine Anzeige ausliefern.',
            ));
        }

        $kennungen = $anzeige->image_hashes ?? [];

        // **Was leer ist, geht nicht mit.** `description` ist nullable, und ein
        // leerer Wert liess Meta am 23.09.2026 das ganze Creative ablehnen
        // ("Invalid parameter"), nicht nur das Feld.
        $inhalt = array_filter([
            'images' => array_map(
                fn (Bildformat $format): array => [
                    'hash' => (string) ($kennungen[$format->value]['hash'] ?? ''),
                    'adlabels' => [['name' => $this->label($anzeige, $format)]],
                ],
                Bildformat::cases(),
            ),
            'bodies' => self::text($vorschlag->body),
            'titles' => self::text($vorschlag->headline),
            'descriptions' => self::text($vorschlag->description),
            'link_urls' => [['website_url' => $this->ziel()]],
            'call_to_action_types' => [(string) config('mrs.ads.call_to_action')],
            'ad_formats' => ['SINGLE_IMAGE'],
            'asset_customization_rules' => $this->regeln($anzeige),
            'optimization_type' => 'PLACEMENT',
        ], fn (array|string $wert): bool => $wert !== []);

        return $this->schreiber->lege($token, $konto->external_id.'/adcreatives', [
            'name' => (string) $anzeige->name,
            'object_story_spec' => (string) json_encode(['page_id' => $seite], JSON_UNESCAPED_UNICODE),
            'asset_feed_spec' => (string) json_encode($inhalt, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Welche Platzierung welches Format zeigt.
     *
     * **Eine Regel je Plattform**, wie in Metas Beispielen. **Die
     * Auffangregel zuletzt** und ohne Einschraenkung -- als JSON-*Objekt*:
     * ein leeres PHP-Array wuerde `[]`, und Metas Beispiel zeigt `{}`.
     *
     * Fundstelle: developers.facebook.com, "Placement Asset Customization"
     * (Stand 28.06.2026). Die Platzierungen stehen in `mrs.ads.formate`.
     *
     * @return list<array<string, mixed>>
     */
    private function regeln(Ad $anzeige): array
    {
        $regeln = [];
        $auffang = [];

        foreach (Bildformat::cases() as $format) {
            $label = ['name' => $this->label($anzeige, $format)];
            $platzierungen = (array) config('mrs.ads.formate.'.$format->value.'.platzierungen', []);

            if ($platzierungen === []) {
                $auffang[] = ['customization_spec' => new stdClass, 'image_label' => $label];

                continue;
            }

            foreach ($platzierungen as $plattform => $plaetze) {
                $regeln[] = [
                    'customization_spec' => [
                        'publisher_platforms' => [(string) $plattform],
                        $plattform.'_positions' => array_values((array) $plaetze),
                    ],
                    'image_label' => $label,
                ];
            }
        }

        return [...$regeln, ...$auffang];
    }

    /**
     * Das Label eines Formats.
     *
     * **Regel 2 gilt auch hier**: Labels liegen offen im Werbekonto wie ein
     * Kampagnenname. Sie tragen das Merkmal der Anzeige und das Format --
     * sonst nichts.
     */
    private function label(Ad $anzeige, Bildformat $format): string
    {
        return 'anzeige_'.$anzeige->client_token.'_'.$format->value;
    }

    /**
     * @return list<array{text: string}>
     */
    private static function text(?string $wert): array
    {
        return $wert === null || $wert === '' ? [] : [['text' => $wert]];
    }

    /**
     * @param  list<Bildformat>  $formate
     */
    private static function formatliste(array $formate): string
    {
        return implode(', ', array_map(fn (Bildformat $f): string => $f->beschreibung(), $formate));
    }

    /**
     * Die eigene Buchungsseite.
     */
    private function ziel(): string
    {
        $organisation = $this->mandant->current();

        if (! $organisation instanceof Organization) {
            throw new Werbefehler(new Fehlereinordnung('no_tenant', wiederholen: false, zustand: null));
        }

        return route('buchung.zeigen', ['praxis' => $organisation->slug]);
    }

    private function gruppenkennung(Ad $anzeige): string
    {
        $gruppe = $anzeige->set()->first();

        if (! $gruppe instanceof AdSet || str_starts_with($gruppe->external_id, 'lokal-')) {
            // **Die Gruppe fehlt, nicht die Kampagne.** Die alte Meldung sagte
            // "Die Kampagne steht noch nicht bei Meta" -- und wer daraufhin im
            // Werbekonto nachsah, fand sie dort und suchte an der falschen
            // Stelle weiter (24.09.2026).
            //
            // Der Name der Kampagne gehoert dazu: bei drei Kampagnen sagt
            // "die Anzeigengruppe" nicht, welche gemeint ist.
            $kampagne = $gruppe?->campaign()->first();

            throw new Werbefehler(new Fehlereinordnung(
                'adset_not_synced',
                wiederholen: true,
                zustand: null,
                klartext: 'Die Anzeigengruppe steht noch nicht bei Meta'
                    .($kampagne instanceof AdCampaign ? ' — Kampagne „'.$kampagne->name.'"' : '').'.',
            ));
        }

        return $gruppe->external_id;
    }

    private function vermerkeErfolg(Ad $anzeige): void
    {
        $anzeige->sync_state = SyncState::Synced;
        $anzeige->sync_error = null;
        $anzeige->synced_at = CarbonImmutable::now();
        $anzeige->save();
    }

    /**
     * Sucht eine Anzeige mit unserem Merkmal im Namen.
     */
    private function sucheKennung(AdAccount $konto, string $token, string $merkmal): ?string
    {
        foreach ($this->leser->sammle($token, $konto->external_id.'/ads', ['fields' => 'id,name']) as $zeile) {
            if (Kampagnenname::merkmalAus(data_get($zeile, 'name')) === $merkmal) {
                $kennung = data_get($zeile, 'id');

                return is_string($kennung) ? $kennung : null;
            }
        }

        return null;
    }

    private static function klartext(string $kurzgrund): string
    {
        return match ($kurzgrund) {
            'permission_denied' => 'Für das Anlegen von Anzeigen fehlt die Berechtigung des Werbekontos.',
            'no_image_hash' => 'Meta hat die Grafik angenommen, aber keine Kennung dafür geliefert.',
            'no_id' => 'Meta hat die Anzeige angenommen, aber keine Kennung geliefert.',

            // **Warten ist keine Ablehnung.** Beides fiel bis zum 24.09.2026
            // in den Standardfall -- eine Anzeige, die auf ein Rate Limit
            // wartete, meldete der Praxis, Meta habe sie abgelehnt, und
            // schickte sie damit auf die Suche nach einem Fehler, den es
            // nicht gab.
            'rate_limit' => 'Meta hat zu viele Anfragen gemeldet. Wir versuchen es später erneut.',
            'temporary' => 'Meta war vorübergehend nicht erreichbar. Wir versuchen es erneut.',

            default => 'Meta hat die Anzeige abgelehnt. Bitte prüfen Sie Kampagne und Grafik.',
        };
    }
}
