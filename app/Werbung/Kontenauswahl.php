<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Enums\ConnectionStatus;
use App\Models\AdAccount;
use App\Werbung\Meta\Graphleser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

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

    /** Nur diese Freigaben tragen Werbekonten. */
    private const WERBEFREIGABEN = ['ads_management', 'ads_read'];

    /**
     * Die Werbekonten hinter diesem Token.
     *
     * **Zwei Arten Token, zwei Wege -- und dem Token sieht man seine Art
     * nicht an.**
     *
     * Ein gewoehnliches Nutzertoken findet seine Konten unter
     * `me/adaccounts`. Ein **Systemnutzer-Token** nicht: dort ist `me` der
     * Systemnutzer, und den gibt es als Graph-Objekt gar nicht. Meta
     * antwortet auf `me` zuerst mit Code 200 ("keine Berechtigung") und dann
     * mit Code 100 ("Object with ID 'me' does not exist") -- beides sieht aus
     * wie ein Rechteproblem und ist keines.
     *
     * Der zweite Weg fragt deshalb nicht das Token, sondern **Meta ueber das
     * Token**: `debug_token` nennt zu jeder erteilten Freigabe die Objekte,
     * fuer die sie gilt. Bei `ads_management` und `ads_read` sind das genau
     * die Werbekonten, die die Praxis im Anmeldedialog ausgewaehlt hat.
     *
     * Aufgefallen am 24.09.2026: Login-Konfiguration vollstaendig, alle
     * Berechtigungen erteilt, Asset-Typ Werbekonten angefragt -- und das
     * Verbinden scheiterte bei jedem Versuch.
     *
     * @return list<Werbekontoangabe>
     */
    public function verfuegbare(string $token): array
    {
        try {
            // Hat die Kante geantwortet, ist ihre Antwort die Wahrheit --
            // auch eine leere. Ein Nutzertoken ohne Werbekonto soll nicht in
            // eine Fehlermeldung laufen.
            return $this->leseKante($token, 'me/adaccounts');
        } catch (Werbefehler $fehler) {
            // Nur eine Abfuhr fuehrt weiter. Ein totes Token oder ein Rate
            // Limit wird auf dem zweiten Weg nicht besser, und ein zweiter
            // Aufruf verdeckte den eigentlichen Grund.
            if (! in_array($fehler->einordnung->kurzgrund, ['permission_missing', 'rejected'], true)) {
                throw $fehler;
            }
        }

        return $this->ausFreigaben($token);
    }

    /**
     * Die Werbekonten aus den Freigaben des Tokens.
     *
     * @return list<Werbekontoangabe>
     */
    private function ausFreigaben(string $token): array
    {
        $konten = [];

        foreach ($this->freigegebeneKennungen($token) as $kennung) {
            $angabe = $this->leseKonto($token, $kennung);

            if ($angabe instanceof Werbekontoangabe) {
                $konten[] = $angabe;
            }
        }

        return $konten;
    }

    /**
     * Fragt Meta, wofuer dieses Token freigegeben ist.
     *
     * **Gefragt wird mit dem App-Token**, nicht mit dem Zugang selbst:
     * `debug_token` beantwortet nur, wer die App ist, Fragen ueber ein
     * fremdes Token.
     *
     * @return list<string>
     */
    private function freigegebeneKennungen(string $token): array
    {
        $antwort = $this->leser->knoten(
            (string) config('mrs.meta.app_id').'|'.(string) config('mrs.meta.app_secret'),
            'debug_token',
            ['input_token' => $token],
        );

        $fein = data_get($antwort, 'data.granular_scopes');

        // **Ins Protokoll, ohne das Token.** Wenn auch dieser Weg einmal
        // nicht traegt, ist die Art des Tokens die erste Frage -- und die
        // steht hier, nicht im Zugang.
        Log::info('Metas Auskunft zum Zugang.', [
            'art' => data_get($antwort, 'data.type'),
            'app' => data_get($antwort, 'data.app_id'),
            'profil' => data_get($antwort, 'data.profile_id'),
            'gueltig' => data_get($antwort, 'data.is_valid'),
            'freigaben' => data_get($antwort, 'data.scopes'),
            'objekte' => $fein,
        ]);

        if (! is_array($fein)) {
            return [];
        }

        $kennungen = [];

        foreach ($fein as $eintrag) {
            if (! in_array(data_get($eintrag, 'scope'), self::WERBEFREIGABEN, true)) {
                continue;
            }

            $ziele = data_get($eintrag, 'target_ids');

            foreach (is_array($ziele) ? $ziele : [] as $ziel) {
                if (! is_string($ziel) && ! is_int($ziel)) {
                    continue;
                }

                // Meta nennt die Ziele mal mit, mal ohne `act_`. Die
                // Kennung eines Werbekontos traegt es immer.
                $kennungen[] = str_starts_with((string) $ziel, 'act_')
                    ? (string) $ziel
                    : 'act_'.(string) $ziel;
            }
        }

        return array_values(array_unique($kennungen));
    }

    /** Ein einzelnes Werbekonto, ueber seine Kennung. */
    private function leseKonto(string $token, string $kennung): ?Werbekontoangabe
    {
        return $this->zurAngabe($this->leser->knoten($token, $kennung, [
            'fields' => 'id,name,currency,timezone_name,account_status,business',
        ]));
    }

    /**
     * Eine Kante lesen und in Angaben uebersetzen.
     *
     * @return list<Werbekontoangabe>
     */
    private function leseKante(string $token, string $pfad): array
    {
        $zeilen = $this->leser->sammle($token, $pfad, [
            'fields' => 'id,name,currency,timezone_name,account_status,business',
        ]);

        $konten = [];

        foreach ($zeilen as $zeile) {
            $angabe = $this->zurAngabe($zeile);

            if ($angabe instanceof Werbekontoangabe) {
                $konten[] = $angabe;
            }
        }

        return $konten;
    }

    /**
     * Eine Zeile aus Metas Antwort als Angabe.
     *
     * @param  array<string, mixed>  $zeile
     */
    private function zurAngabe(array $zeile): ?Werbekontoangabe
    {
        $kennung = data_get($zeile, 'id');

        if (! is_string($kennung) || $kennung === '') {
            return null;
        }

        return new Werbekontoangabe(
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
