<?php

declare(strict_types=1);

namespace App\Abrechnung\Stripe;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Der Zugang zu Stripe -- duenn, wie die anderen Clients dieses Projekts.
 *
 * **Kein Cashier.** Der bringt eigene Tabellen, eigene Routen und eine eigene
 * Vorstellung davon, wie ein Abo aussieht. Gebraucht werden drei Dinge: einen
 * Kunden anlegen, eine Kasse oeffnen, das Portal oeffnen -- der Rest kommt
 * ueber den Webhook zurueck.
 *
 * **Schreibende Aufrufe laufen nicht im Anfragezyklus** (Regel 4)? Doch, und
 * das ist hier richtig: eine Kasse zu oeffnen ist eine Handlung, auf deren
 * Ergebnis der Mensch am Bildschirm wartet. Faellt Stripe aus, bekommt er
 * eine Meldung -- und nicht eine Weiterleitung ins Leere.
 */
final class Stripeclient
{
    public function angebunden(): bool
    {
        $schluessel = config('services.stripe.key');

        return is_string($schluessel) && $schluessel !== '';
    }

    /** Legt den Kunden an -- oder gibt den vorhandenen zurueck. */
    public function kunde(Organization $praxis, ?string $vorhanden, string $email): ?string
    {
        if (is_string($vorhanden) && $vorhanden !== '') {
            return $vorhanden;
        }

        $antwort = $this->anfrage()->asForm()->post($this->adresse('customers'), [
            'name' => $praxis->name,
            'email' => $email,
            'metadata[organization]' => (string) $praxis->uuid,
        ]);

        $kennung = data_get($antwort->json(), 'id');

        return $antwort->successful() && is_string($kennung) ? $kennung : null;
    }

    /**
     * Die Kasse fuer das Abo oder fuer eine Aufstockung.
     *
     * @return string|null Die Adresse, auf die weitergeleitet wird
     */
    public function kasse(
        string $kunde,
        string $preis,
        string $zurueck,
        string $modus = 'subscription',

        /** Bilder werden einzeln nachgekauft (WP-31), alles andere in Bloecken. */
        int $menge = 1,

        /**
         * Was gekauft wird -- 'nachrichten', 'agentenlaeufe' oder 'bilder'.
         *
         * **Ohne diese Angabe raet der Webhook.** Bis WP-31 schrieb er nach
         * jeder Zahlung beides gut, weil `checkout.session.completed` die
         * Positionen nicht mitliefert. Mit einem dritten Artikel waere aus
         * dem Ungenauen ein Fehler geworden: wer Bilder kauft, bekaeme
         * Nachrichten.
         */
        ?string $artikel = null,

        /**
         * Eine einmalige Einrichtung zum Abschluss (docs/produkt.md,
         * Preismodell). Stripe nimmt einen einmaligen Preis neben dem
         * wiederkehrenden in dieselbe Kasse und stellt ihn mit der ersten
         * Rechnung.
         */
        ?string $einrichtung = null,
    ): ?string {
        $posten = [
            'line_items[0][price]' => $preis,
            'line_items[0][quantity]' => max(1, $menge),
        ];

        if ($modus === 'subscription' && is_string($einrichtung) && $einrichtung !== '') {
            $posten['line_items[1][price]'] = $einrichtung;
            $posten['line_items[1][quantity]'] = 1;
        }

        $antwort = $this->anfrage()->asForm()->post($this->adresse('checkout/sessions'), [
            'customer' => $kunde,
            'mode' => $modus,
            ...$posten,
            'success_url' => $zurueck.'?abo=ok',
            'cancel_url' => $zurueck,

            // SEPA-Lastschrift ist in Deutschland Pflichtprogramm; ohne sie
            // scheitert der Abschluss an der Zahlungsart.
            'payment_method_types[0]' => 'card',
            'payment_method_types[1]' => 'sepa_debit',

            // **Netto zuzueglich USt., die Stripe Tax rechnet**
            // (docs/produkt.md). Die Preise tragen `tax_behavior=exclusive` --
            // ohne diese Angabe zoege Stripe trotzdem nur den Nettobetrag
            // ein. Stripe braucht dafuer die Anschrift; der Kunde steht
            // schon, also schreibt die Kasse sie an ihn zurueck. Die
            // USt-IdNr. der Praxis gehoert auf die Rechnung, bei Oesterreich
            // wegen der Umkehr der Steuerschuld.
            'automatic_tax[enabled]' => 'true',
            'billing_address_collection' => 'required',
            'customer_update[address]' => 'auto',
            'customer_update[name]' => 'auto',
            'tax_id_collection[enabled]' => 'true',

            'metadata[artikel]' => (string) $artikel,
            'metadata[menge]' => (string) max(1, $menge),
        ]);

        $adresse = data_get($antwort->json(), 'url');

        return $antwort->successful() && is_string($adresse) ? $adresse : null;
    }

    /**
     * Das Kundenportal: Rechnungen, Zahlungsart, Kuendigung.
     *
     * **Mit der eigenen Konfiguration**, wenn `mrs:stripe-einrichten` sie
     * angelegt hat -- sonst haengt das Portal an einer Standardkonfiguration,
     * die jemand im Dashboard gespeichert haben muss.
     */
    public function portal(string $kunde, string $zurueck): ?string
    {
        $antwort = $this->anfrage()->asForm()->post($this->adresse('billing_portal/sessions'), array_filter([
            'customer' => $kunde,
            'return_url' => $zurueck,
            'configuration' => $this->eigenePortalkonfiguration(),
        ], fn (?string $wert): bool => $wert !== null));

        $adresse = data_get($antwort->json(), 'url');

        return $antwort->successful() && is_string($adresse) ? $adresse : null;
    }

    /**
     * Ein Posten fuer die naechste Rechnung des Abos (Entscheidung B14).
     *
     * **Nicht im Anfragezyklus** (Regel 4) -- anders als Kasse und Portal
     * wartet hier niemand am Bildschirm. Aufgerufen wird aus einem Auftrag,
     * mit Idempotenzschluessel: eine Wiederholung nach verlorener Antwort
     * legt keinen zweiten Posten an.
     *
     * @return string|null Die Kennung des Postens bei Stripe
     */
    public function rechnungsposten(
        string $kunde,
        string $abo,
        int $betragCent,
        string $beschreibung,
        string $idempotenz,
    ): ?string {
        $antwort = $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('invoiceitems'), [
                'customer' => $kunde,
                'subscription' => $abo,
                'amount' => $betragCent,
                'currency' => 'eur',
                'description' => $beschreibung,
            ]);

        $kennung = data_get($antwort->json(), 'id');

        return $antwort->successful() && is_string($kennung) ? $kennung : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Eingriffe in ein Abo (WP-34c, B17)
    |--------------------------------------------------------------------------
    |
    | **Nicht im Anfragezyklus** (Regel 4): aufgerufen aus dem Auftrag
    | AboEingriffAusfuehren, jeder mit dem Idempotenzschluessel seines
    | Eingriffs. Zurueck kommt die Antwort selbst -- der Auftrag entscheidet,
    | ob wiederholt wird oder ob es endgueltig gescheitert ist.
    |
    | Den Zustand danach meldet der Webhook, nicht diese Antwort.
    |
    */

    /** Der Einzug ruht; Stripe verwirft Rechnungen bis zum Fortsetzen. */
    public function pausiere(string $abo, ?CarbonImmutable $bis, string $idempotenz): ClientResponse
    {
        return $this->aendereAbo($abo, $idempotenz, array_filter([
            'pause_collection[behavior]' => 'void',
            'pause_collection[resumes_at]' => $bis?->getTimestamp(),
        ], fn (mixed $wert): bool => $wert !== null));
    }

    public function setzeFort(string $abo, string $idempotenz): ClientResponse
    {
        // Ein leerer Wert hebt das Objekt bei Stripe auf.
        return $this->aendereAbo($abo, $idempotenz, ['pause_collection' => '']);
    }

    public function kuendigeZumPeriodenende(string $abo, string $idempotenz): ClientResponse
    {
        return $this->aendereAbo($abo, $idempotenz, ['cancel_at_period_end' => 'true']);
    }

    public function nimmKuendigungZurueck(string $abo, string $idempotenz): ClientResponse
    {
        return $this->aendereAbo($abo, $idempotenz, ['cancel_at_period_end' => 'false']);
    }

    public function kuendigeSofort(string $abo, string $idempotenz): ClientResponse
    {
        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->delete($this->adresse('subscriptions/'.rawurlencode($abo)));
    }

    /** Der Gratismonat: ein Gutschein mit 100 %, einmal (B17). */
    public function gewaehreGutschein(string $abo, string $gutschein, string $idempotenz): ClientResponse
    {
        return $this->aendereAbo($abo, $idempotenz, ['discounts[0][coupon]' => $gutschein]);
    }

    /**
     * Der Gutschein fuer den Gratismonat -- **angelegt, wenn es ihn nicht
     * gibt** (28.09.2026). Bis hier entstand er von Hand im Dashboard, und
     * ohne seine Kennung in der Umgebung ging kein Gratismonat.
     *
     * Die Kennung ist fest gewaehlt, nicht von Stripe vergeben: ein zweiter
     * Lauf findet ihn wieder, statt einen zweiten anzulegen.
     */
    public function stelleGutscheinSicher(string $gutschein, string $idempotenz): ClientResponse
    {
        $vorhanden = $this->anfrage()->get($this->adresse('coupons/'.rawurlencode($gutschein)));

        if ($vorhanden->status() !== 404) {
            return $vorhanden;
        }

        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('coupons'), [
                'id' => $gutschein,
                'name' => 'Gratismonat',
                'percent_off' => 100,
                'duration' => 'once',
            ]);
    }

    /**
     * @param  array<string, mixed>  $felder
     */
    private function aendereAbo(string $abo, string $idempotenz, array $felder): ClientResponse
    {
        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('subscriptions/'.rawurlencode($abo)), $felder);
    }

    /*
    |--------------------------------------------------------------------------
    | Paketfassungen (WP-06b, B20)
    |--------------------------------------------------------------------------
    |
    | Ein Preis bei Stripe ist unveraenderlich. Eine neue Fassung bekommt
    | neue Preise unter demselben Produkt; Grund- und Einrichtungspreis der
    | alten werden archiviert -- das sperrt sie fuer neue Abschluesse,
    | laufende Abos rechnen weiter.
    |
    */

    public function preis(string $preis): ClientResponse
    {
        return $this->anfrage()->get($this->adresse('prices/'.rawurlencode($preis)));
    }

    public function legeProduktAn(string $name, string $idempotenz): ClientResponse
    {
        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('products'), ['name' => $name]);
    }

    public function benenneProdukt(string $produkt, string $name, string $idempotenz): ClientResponse
    {
        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('products/'.rawurlencode($produkt)), ['name' => $name]);
    }

    /**
     * Ein Preis in Euro-Cent, netto -- monatlich wiederkehrend oder einmalig.
     */
    public function legePreisAn(string $produkt, int $cent, bool $monatlich, string $bezeichnung, string $idempotenz): ClientResponse
    {
        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('prices'), array_filter([
                'product' => $produkt,
                'unit_amount' => $cent,
                'currency' => 'eur',
                'nickname' => $bezeichnung,
                'tax_behavior' => 'exclusive',
                'recurring[interval]' => $monatlich ? 'month' : null,
            ], fn (mixed $wert): bool => $wert !== null));
    }

    public function archivierePreis(string $preis, string $idempotenz): ClientResponse
    {
        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('prices/'.rawurlencode($preis)), ['active' => 'false']);
    }

    public function abo(string $abo): ClientResponse
    {
        return $this->anfrage()->get($this->adresse('subscriptions/'.rawurlencode($abo)));
    }

    /**
     * Stellt die Position eines Abos auf einen neuen Preis um -- **ohne
     * anteilige Verrechnung**: der laufende Zeitraum ist bezahlt, die naechste
     * Rechnung kommt zum neuen Preis.
     */
    public function stelleAboUm(string $abo, string $position, string $preis, string $idempotenz): ClientResponse
    {
        return $this->aendereAbo($abo, $idempotenz, [
            'items[0][id]' => $position,
            'items[0][price]' => $preis,
            'proration_behavior' => 'none',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Einrichtung (mrs:stripe-einrichten)
    |--------------------------------------------------------------------------
    |
    | **Nur noch Schluessel eintragen** (28.09.2026). Webhook-Endpunkt,
    | Gutschein und Kundenportal entstehen aus dem Befehl, nicht von Hand im
    | Dashboard -- je Umgebung, Test wie Live. Jeder Aufruf findet zuerst, was
    | es schon gibt; ein zweiter Lauf legt nichts an.
    |
    */

    /** Test oder Live -- am Praefix des Schluessels. */
    public function live(): bool
    {
        $schluessel = (string) config('services.stripe.key');

        return str_starts_with($schluessel, 'sk_live_') || str_starts_with($schluessel, 'rk_live_');
    }

    public function webhookEndpunkte(): ClientResponse
    {
        return $this->anfrage()->get($this->adresse('webhook_endpoints'), ['limit' => 100]);
    }

    /**
     * **Auf der festgenagelten Version** (WP-34c) -- der Endpunkt liefert in
     * der Form, in der der Client liest. Eine Version laesst sich an einem
     * bestehenden Endpunkt nicht aendern; deshalb hier und nur hier.
     *
     * Die Antwort traegt das Signaturgeheimnis. **Stripe nennt es nur dieses
     * eine Mal.**
     *
     * @param  list<string>  $ereignisse
     */
    public function legeWebhookAn(string $adresse, array $ereignisse, string $idempotenz): ClientResponse
    {
        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('webhook_endpoints'), [
                'url' => $adresse,
                'api_version' => (string) config('services.stripe.api_version'),
                'enabled_events' => $ereignisse,
                'description' => 'Mrs. Beauty -- Abo und Kasse',
                'metadata[mrs]' => 'webhook',
            ]);
    }

    /** @param  list<string>  $ereignisse */
    public function aendereWebhook(string $endpunkt, array $ereignisse, string $idempotenz): ClientResponse
    {
        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse('webhook_endpoints/'.rawurlencode($endpunkt)), ['enabled_events' => $ereignisse]);
    }

    public function entferneWebhook(string $endpunkt): ClientResponse
    {
        return $this->anfrage()->delete($this->adresse('webhook_endpoints/'.rawurlencode($endpunkt)));
    }

    public function portalkonfigurationen(): ClientResponse
    {
        return $this->anfrage()->get($this->adresse('billing_portal/configurations'), ['limit' => 100, 'active' => 'true']);
    }

    /**
     * Legt die Konfiguration des Kundenportals an oder gleicht sie ab.
     *
     * **Kein Tarifwechsel, keine Menge** -- das Paket wird im Produkt gepflegt
     * (B20), eine Stufe bleibt eine Stufe (B10). Gekuendigt wird zum
     * Periodenende; der Monat ist bezahlt.
     */
    public function speicherePortalkonfiguration(?string $vorhanden, string $idempotenz): ClientResponse
    {
        $felder = [
            'business_profile[headline]' => (string) config('app.name'),
            'features[invoice_history][enabled]' => 'true',
            'features[payment_method_update][enabled]' => 'true',
            'features[customer_update][enabled]' => 'true',
            'features[customer_update][allowed_updates]' => ['name', 'address', 'tax_id'],
            'features[subscription_cancel][enabled]' => 'true',
            'features[subscription_cancel][mode]' => 'at_period_end',
            'features[subscription_update][enabled]' => 'false',
            'metadata[mrs]' => 'portal',
        ];

        $pfad = $vorhanden === null ? 'billing_portal/configurations' : 'billing_portal/configurations/'.rawurlencode($vorhanden);

        return $this->anfrage()
            ->withHeaders(['Idempotency-Key' => $idempotenz])
            ->asForm()
            ->post($this->adresse($pfad), $felder);
    }

    /** Die Konfiguration, die `mrs:stripe-einrichten` angelegt hat -- oder keine. */
    public function eigenePortalkonfiguration(): ?string
    {
        try {
            $antwort = $this->portalkonfigurationen();
        } catch (ConnectionException) {
            return null;
        }

        if (! $antwort->successful()) {
            return null;
        }

        foreach ((array) data_get($antwort->json(), 'data', []) as $konfiguration) {
            if (data_get($konfiguration, 'metadata.mrs') === 'portal' && is_string(data_get($konfiguration, 'id'))) {
                return (string) data_get($konfiguration, 'id');
            }
        }

        return null;
    }

    /** Rechnet Stripe Tax? Ohne lehnt jede Kasse mit `automatic_tax` ab. */
    public function steuereinstellungen(): ClientResponse
    {
        return $this->anfrage()->get($this->adresse('tax/settings'));
    }

    private function adresse(string $pfad): string
    {
        return rtrim((string) config('services.stripe.url'), '/').'/v1/'.$pfad;
    }

    private function anfrage(): PendingRequest
    {
        return Http::withToken((string) config('services.stripe.key'))
            // **Festgenagelt** (WP-34c): ohne Header gilt die Version des
            // Kontos, und ein Wechsel dort verschiebt Felder, ohne dass hier
            // jemand davon erfaehrt.
            ->withHeaders(['Stripe-Version' => (string) config('services.stripe.api_version')])
            ->acceptJson()
            ->timeout(20)
            // Nur Ausfaelle werden wiederholt. Eine Ablehnung wird beim
            // zweiten Versuch nicht angenommen -- und eine zweite Kasse fuer
            // dieselbe Absicht waere eine zu viel.
            ->retry(2, 300, function (Throwable $ausnahme): bool {
                if ($ausnahme instanceof ConnectionException) {
                    return true;
                }

                return $ausnahme instanceof RequestException && $ausnahme->response->serverError();
            }, throw: false);
    }
}
