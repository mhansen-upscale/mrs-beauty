<?php

declare(strict_types=1);

namespace App\Anzeigen\KieAi;

use App\Anzeigen\Bild;
use App\Anzeigen\Bildmodell;
use App\Anzeigen\BildNichtErzeugt;
use App\Anzeigen\Bildsatz;
use App\Enums\Bildformat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Bilderzeugung ueber kie.ai.
 *
 * **Zwei Aufrufe, nicht einer.** `createTask` nimmt den Auftrag an und
 * liefert eine Kennung; `recordInfo` sagt, wie weit er ist. Das Ergebnis
 * steckt in `data.resultJson` -- **einer Zeichenkette, die selbst wieder JSON
 * enthaelt**. Wer sie als Objekt liest, findet nichts und bekommt keinen
 * Hinweis darauf, warum.
 *
 * Belegt: docs.kie.ai, „Get Task Details" und die Modellseiten unter
 * /market. Die erste Fassung dieser Klasse war geraten und lag an vier
 * Stellen daneben -- der Pfad, die Form des Rumpfes, der Ort des Ergebnisses
 * und ein Loeschweg, den es nicht gibt.
 *
 * **Entscheidung C10 laesst sich nur zur Haelfte einloesen.** Erzeugen,
 * herunterladen und bei uns ablegen: ja. Beim Anbieter loeschen: kie.ai
 * dokumentiert keinen Weg dafuer. Deshalb steht hier kein erfundener Aufruf,
 * sondern dieser Absatz.
 *
 * **Ein Auftrag je Format** (WP-31b). Alle werden angelegt, bevor der erste
 * abgefragt wird: nacheinander waeren es bis zu neun Minuten, und der Auftrag
 * in der Warteschlange hat zehn.
 */
final class KieModell implements Bildmodell
{
    public function angebunden(): bool
    {
        $schluessel = config('services.kie.key');

        return is_string($schluessel) && $schluessel !== '';
    }

    public function erzeuge(array $auftraege): Bildsatz
    {
        if (! $this->angebunden()) {
            throw new BildNichtErzeugt('Es ist kein Bildmodell angebunden.');
        }

        $fehler = [];
        $kennungen = [];

        foreach ($auftraege as $format => $auftrag) {
            try {
                $kennungen[$format] = $this->beauftrage($format, $auftrag);
            } catch (BildNichtErzeugt $grund) {
                $fehler[$format] = $grund->getMessage();
            }
        }

        [$adressen, $nichtFertig] = $this->warteAufErgebnisse($kennungen);
        $fehler += $nichtFertig;

        $bilder = [];

        foreach ($adressen as $format => $adresse) {
            try {
                $bilder[$format] = $this->lade($adresse);
            } catch (BildNichtErzeugt $grund) {
                $fehler[$format] = $grund->getMessage();
            }
        }

        $reihenfolge = array_keys($auftraege);

        return new Bildsatz($this->ordne($reihenfolge, $bilder), $this->ordne($reihenfolge, $fehler));
    }

    private function beauftrage(string $format, string $auftrag): string
    {
        $felder = config('services.kie.formate.'.$format);

        // **Ohne Seitenverhaeltnis kein Auftrag.** Das Modell waehlte sonst
        // `auto`, und die Grafik kaeme im falschen Format zurueck -- bezahlt
        // und ohne Fehler.
        if (! is_array($felder) || $felder === []) {
            throw new BildNichtErzeugt(
                'Für das Format '.(Bildformat::tryFrom($format)?->seitenverhaeltnis() ?? $format)
                .' ist beim Bildmodell kein Seitenverhältnis eingestellt (services.kie.formate).'
            );
        }

        try {
            $antwort = $this->client()->post($this->basis().'/createTask', [
                'model' => (string) config('services.kie.model'),

                // **Der Auftrag steht unter `input`**, nicht oben -- zusammen
                // mit den Feldern, die das eingestellte Modell kennt. Welche
                // das sind, steht in der Konfiguration: sie unterscheiden sich
                // von Modell zu Modell. Was je Format gilt, hat Vorrang.
                'input' => ['prompt' => $auftrag] + $felder + (array) config('services.kie.input', []),
            ]);
        } catch (ConnectionException) {
            throw new BildNichtErzeugt('kie.ai war nicht erreichbar.');
        }

        if ($antwort->failed()) {
            throw new BildNichtErzeugt($this->meldung('kie.ai hat den Auftrag abgelehnt', $antwort));
        }

        $kennung = data_get($antwort->json(), 'data.taskId');

        if (! is_string($kennung) || $kennung === '') {
            // **Mit der Antwort im Text.** Eine Meldung, die verschweigt, was
            // zurueckkam, laesst nur raten -- und genau daran ist die erste
            // Fassung gescheitert.
            throw new BildNichtErzeugt($this->meldung('kie.ai hat keine Auftragskennung geliefert', $antwort));
        }

        return $kennung;
    }

    /**
     * Fragt den Stand aller Auftraege ab, bis jeder ein Bild hat oder
     * aufgegeben ist.
     *
     * **Mit harter Obergrenze**, wie beim Paging in WP-26: ein Auftrag, der
     * nie fertig wird, haelt sonst die Warteschlange an. Die Grenze gilt fuer
     * alle zusammen, nicht je Auftrag -- sie laufen ja gleichzeitig.
     *
     * @param  array<string, string>  $kennungen  Auftragskennung je Format
     * @return array{0: array<string, string>, 1: array<string, string>} Bildadressen und Gruende, je Format
     */
    private function warteAufErgebnisse(array $kennungen): array
    {
        $offen = $kennungen;
        $adressen = [];
        $fehler = [];
        $versuche = max(1, (int) config('services.kie.max_polls'));

        for ($versuch = 0; $versuch < $versuche && $offen !== []; $versuch++) {
            foreach ($offen as $format => $kennung) {
                try {
                    $adresse = $this->frageNach($kennung);
                } catch (BildNichtErzeugt $grund) {
                    $fehler[$format] = $grund->getMessage();
                    unset($offen[$format]);

                    continue;
                }

                if ($adresse !== null) {
                    $adressen[$format] = $adresse;
                    unset($offen[$format]);
                }
            }

            if ($offen !== []) {
                // waiting, queuing, generating -- weiter warten.
                usleep((int) config('services.kie.poll_ms') * 1000);
            }
        }

        foreach ($offen as $format => $kennung) {
            $fehler[$format] = 'kie.ai wurde nicht rechtzeitig fertig (Auftrag '.$kennung.').';
        }

        return [$adressen, $fehler];
    }

    /**
     * Fragt einmal nach. Null heisst: noch nicht fertig.
     */
    private function frageNach(string $kennung): ?string
    {
        try {
            $antwort = $this->client()->get($this->basis().'/recordInfo', ['taskId' => $kennung]);
        } catch (ConnectionException) {
            // **Ein Aussetzer beim Nachfragen ist kein gescheitertes Bild.**
            // Der Auftrag laeuft beim Anbieter weiter und ist bezahlt; die
            // naechste Runde fragt wieder.
            return null;
        }

        if ($antwort->failed()) {
            throw new BildNichtErzeugt($this->meldung('kie.ai antwortet nicht', $antwort));
        }

        $zustand = data_get($antwort->json(), 'data.state');

        if ($zustand === 'fail') {
            throw new BildNichtErzeugt($this->meldung('kie.ai konnte kein Bild erzeugen', $antwort));
        }

        return $zustand === 'success' ? $this->adresseAus($antwort) : null;
    }

    /**
     * In der Reihenfolge des Auftrags, nicht in der des Fertigwerdens.
     *
     * @template T
     *
     * @param  list<string>  $reihenfolge
     * @param  array<string, T>  $werte
     * @return array<string, T>
     */
    private function ordne(array $reihenfolge, array $werte): array
    {
        $geordnet = [];

        foreach ($reihenfolge as $format) {
            if (array_key_exists($format, $werte)) {
                $geordnet[$format] = $werte[$format];
            }
        }

        return $geordnet;
    }

    /**
     * Die Bildadresse aus `data.resultJson`.
     *
     * **Eine Zeichenkette, die JSON enthaelt.** Genau hier lag der Fehler der
     * ersten Fassung: sie suchte `data.resultUrls` als Feld und fand nichts.
     */
    private function adresseAus(Response $antwort): string
    {
        $roh = data_get($antwort->json(), 'data.resultJson');
        $ergebnis = is_string($roh) ? json_decode($roh, true) : $roh;

        $adresse = data_get(is_array($ergebnis) ? $ergebnis : [], 'resultUrls.0');

        if (! is_string($adresse) || $adresse === '') {
            throw new BildNichtErzeugt($this->meldung('kie.ai meldet Erfolg, liefert aber keine Bildadresse', $antwort));
        }

        return $adresse;
    }

    private function lade(string $adresse): Bild
    {
        try {
            $antwort = Http::timeout(60)->get($adresse);
        } catch (ConnectionException) {
            throw new BildNichtErzeugt('Das erzeugte Bild war nicht abrufbar.');
        }

        if ($antwort->failed()) {
            throw new BildNichtErzeugt('Das erzeugte Bild war nicht abrufbar ('.$antwort->status().').');
        }

        // Der Kopf traegt oft noch ein Zeichensatz-Anhaengsel.
        $mime = trim(explode(';', (string) $antwort->header('Content-Type'))[0]);

        // **Nur Rasterbilder**, wie beim Logo in WP-07: eine SVG-Datei kann
        // ein Skript enthalten, und ein Virenscanner findet so etwas nicht.
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new BildNichtErzeugt('kie.ai hat eine unerwartete Dateiart geliefert: '.($mime === '' ? 'keine Angabe' : $mime));
        }

        return new Bild(
            inhalt: $antwort->body(),
            mime: $mime,
            modell: (string) config('services.kie.model'),
        );
    }

    /**
     * Eine Meldung, die sagt, was zurueckkam.
     *
     * Gekuerzt, damit sie in eine Oberflaeche passt -- aber nicht weggelassen.
     */
    private function meldung(string $satz, Response $antwort): string
    {
        $rumpf = trim($antwort->body());

        return $satz.' (HTTP '.$antwort->status().'): '
            .($rumpf === '' ? 'leere Antwort' : mb_substr($rumpf, 0, 300));
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.kie.key'))
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function basis(): string
    {
        return rtrim((string) config('services.kie.url'), '/');
    }
}
