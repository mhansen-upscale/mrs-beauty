<?php

declare(strict_types=1);

namespace App\Werbung\Verwaltung;

use App\Datenschutz\Anhangspeicher;
use App\Enums\SyncState;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\AdSuggestion;
use App\Models\Attachment;
use App\Models\Organization;
use App\Support\Fehlereinordnung;
use App\Tenancy\TenantContext;
use App\Werbung\Meta\Graphleser;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;

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
     * Drei Schritte, in dieser Reihenfolge: Bild hochladen, Creative anlegen,
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

        $anzeige->image_hash ??= $this->ladeBildHoch($vorschlag, $konto, $token);
        $anzeige->save();

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

        $anzeige->sync_state = SyncState::Failed;
        $anzeige->sync_error = $fehler->einordnung->klartext ?? self::klartext($fehler->einordnung->kurzgrund);
        $anzeige->save();
    }

    /**
     * Laedt die Grafik hoch und gibt Metas Bildkennung zurueck.
     *
     * Das Bild liegt bei uns (C10) und geht als Base64 hinaus -- Metas
     * `/adimages` nimmt es so entgegen, ohne Multipart.
     */
    private function ladeBildHoch(AdSuggestion $vorschlag, AdAccount $konto, string $token): string
    {
        $anhang = $vorschlag->bild();

        if (! $anhang instanceof Attachment) {
            throw new Werbefehler(new Fehlereinordnung(
                'no_image',
                wiederholen: false,
                zustand: null,
                klartext: 'Zu diesem Entwurf gibt es keine Grafik mehr.',
            ));
        }

        $antwort = $this->schreiber->legeRoh($token, $konto->external_id.'/adimages', [
            'bytes' => base64_encode($this->anhaenge->rohinhalt($anhang)),
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
     * Das Creative: Bild, Text, Ueberschrift, Schaltflaeche und das Ziel.
     *
     * **Hier darf die Leistung stehen** (C9). Das Ziel ist die eigene
     * Buchungsseite -- eine fremde Zielseite koennte alles behaupten, und
     * geprueft haben wir nur unsere.
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

        // **Was leer ist, geht nicht mit.** `description` ist nullable, und ein
        // `"description": null` beantwortet Meta mit "Invalid parameter" --
        // es lehnt damit das ganze Creative ab, nicht nur das Feld. Der
        // Handlungsaufruf steht ausserhalb der Filterung: er ist ein Array
        // und immer gesetzt.
        $inhalt = array_filter([
            'image_hash' => $anzeige->image_hash,
            'link' => $this->ziel(),
            'message' => $vorschlag->body,
            'name' => $vorschlag->headline,
            'description' => $vorschlag->description,
        ], fn (?string $wert): bool => $wert !== null && $wert !== '');

        $inhalt['call_to_action'] = ['type' => (string) config('mrs.ads.call_to_action')];

        return $this->schreiber->lege($token, $konto->external_id.'/adcreatives', [
            'name' => (string) $anzeige->name,
            'object_story_spec' => (string) json_encode([
                'page_id' => $seite,
                'link_data' => $inhalt,
            ], JSON_UNESCAPED_UNICODE),
        ]);
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
            throw new Werbefehler(new Fehlereinordnung(
                'adset_not_synced',
                wiederholen: true,
                zustand: null,
                klartext: 'Die Kampagne steht noch nicht bei Meta. Die Anzeige geht hinaus, sobald sie dort ist.',
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
            default => 'Meta hat die Anzeige abgelehnt. Bitte prüfen Sie Kampagne und Grafik.',
        };
    }
}
