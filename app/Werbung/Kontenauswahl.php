<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Enums\ConnectionStatus;
use App\Models\AdAccount;
use App\Werbung\Meta\Graphleser;
use Carbon\CarbonImmutable;

/**
 * Welche Werbekonten die Praxis freigegeben hat -- und welches gilt.
 *
 * **Die Auswahl ist ein eigener Schritt, keine Rateoperation.** Wer mehrere
 * Konten in seinem Business Manager hat, gibt oft alle frei. Das falsche zu
 * nehmen faellt spaet auf: die Zahlen sehen plausibel aus, sie gehoeren nur
 * jemand anderem.
 */
final class Kontenauswahl
{
    public function __construct(private readonly Graphleser $leser) {}

    /**
     * Die Werbekonten hinter diesem Token.
     *
     * **Zwei Wege zum selben Ziel, und welcher gilt, haengt an der Art des
     * Tokens.** Ein gewoehnliches Nutzertoken findet seine Konten unter
     * `me/adaccounts`. Ein **Systemnutzer-Token** nicht: dort ist `me` der
     * Systemnutzer, und der traegt seine Konten unter `assigned_ad_accounts`.
     * Meta antwortet auf die falsche Kante nicht mit einer leeren Liste,
     * sondern mit Code 200 -- "keine Berechtigung".
     *
     * Aufgefallen am 24.09.2026: die Login-Konfiguration war vollstaendig,
     * die Berechtigungen alle erteilt, und das Verbinden scheiterte trotzdem
     * bei jedem Versuch. Die Konfiguration stellt Systemnutzer-Token aus --
     * eine Zeile in Metas Oberflaeche, die den ganzen Weg umlegt.
     *
     * **Dem Token sieht man das nicht an.** Deshalb wird gefragt statt
     * geraten: erst die eine Kante, bei einer Abfuhr die andere.
     *
     * @return list<Werbekontoangabe>
     */
    public function verfuegbare(string $token): array
    {
        try {
            // Hat die Kante geantwortet, ist ihre Antwort die Wahrheit --
            // auch eine leere. Ein Nutzertoken ohne Werbekonto soll nicht
            // in eine Fehlermeldung laufen.
            return $this->lese($token, 'me/adaccounts');
        } catch (Werbefehler $fehler) {
            // Nur eine Abfuhr fuehrt weiter. Ein totes Token oder ein Rate
            // Limit wird an der zweiten Kante nicht besser.
            if ($fehler->einordnung->kurzgrund !== 'permission_missing') {
                throw $fehler;
            }
        }

        return $this->lese($token, 'me/assigned_ad_accounts');
    }

    /**
     * Eine Kante lesen und in Angaben uebersetzen.
     *
     * @return list<Werbekontoangabe>
     */
    private function lese(string $token, string $pfad): array
    {
        $zeilen = $this->leser->sammle($token, $pfad, [
            'fields' => 'id,name,currency,timezone_name,account_status,business',
        ]);

        $konten = [];

        foreach ($zeilen as $zeile) {
            $kennung = data_get($zeile, 'id');

            if (! is_string($kennung) || $kennung === '') {
                continue;
            }

            $konten[] = new Werbekontoangabe(
                kennung: $kennung,
                name: $this->text(data_get($zeile, 'name')),
                waehrung: $this->text(data_get($zeile, 'currency')),
                zeitzone: $this->text(data_get($zeile, 'timezone_name')),
                business: $this->text(data_get($zeile, 'business.id')),

                // 1 ist ACTIVE. Alles andere reicht von "ausstehend" bis
                // "gesperrt" -- anbieten kann man es, verbinden sollte es
                // niemand, ohne den Hinweis gesehen zu haben.
                nutzbar: (int) (data_get($zeile, 'account_status') ?? 0) === 1,
            );
        }

        return $konten;
    }

    /**
     * Verbindet genau eines. Ein zweiter Aufruf ersetzt das bestehende Konto,
     * er legt keines daneben.
     */
    public function verbinde(Werbekontoangabe $angabe, Werbetoken $token): AdAccount
    {
        $konto = AdAccount::query()->where('external_id', $angabe->kennung)->first()
            ?? new AdAccount(['external_id' => $angabe->kennung]);

        $konto->name = $angabe->name;
        $konto->currency = $angabe->waehrung;
        $konto->timezone = $angabe->zeitzone;
        $konto->business_external_id = $angabe->business;
        $konto->access_token = $token->zugang;
        $konto->token_expires_at = $token->laeuftAb;
        $konto->status = ConnectionStatus::Active;
        $konto->last_error = null;
        $konto->failed_at = null;
        $konto->connected_at = CarbonImmutable::now();
        $konto->disconnected_at = null;
        $konto->save();

        // Ein Mandant, ein Werbekonto: was vorher verbunden war, gilt nicht
        // mehr. Die gelesene Struktur bleibt stehen, damit Auswertungen nicht
        // ins Leere zeigen.
        AdAccount::query()
            ->whereKeyNot($konto->getKey())
            ->whereNull('disconnected_at')
            ->get()
            ->each(fn (AdAccount $altes) => $this->trenne($altes));

        return $konto->refresh();
    }

    /**
     * Trennt die Verbindung.
     *
     * **Das Token geht, die Struktur bleibt.** Wer die Kampagnen mitloescht,
     * reisst die Verbindung zwischen einem Termin und der Anzeige, die ihn
     * gebracht hat (D13) -- und die ist der Grund, warum es dieses Produkt
     * gibt.
     */
    public function trenne(AdAccount $konto): void
    {
        $konto->access_token = null;
        $konto->token_expires_at = null;
        $konto->disconnected_at = CarbonImmutable::now();
        $konto->save();
    }

    private function text(mixed $wert): ?string
    {
        return is_string($wert) && $wert !== '' ? $wert : null;
    }
}
