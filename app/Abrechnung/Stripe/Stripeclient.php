<?php

declare(strict_types=1);

namespace App\Abrechnung\Stripe;

use App\Models\Organization;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
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

            'metadata[artikel]' => (string) $artikel,
            'metadata[menge]' => (string) max(1, $menge),
        ]);

        $adresse = data_get($antwort->json(), 'url');

        return $antwort->successful() && is_string($adresse) ? $adresse : null;
    }

    /** Das Kundenportal: Rechnungen, Zahlungsart, Kuendigung. */
    public function portal(string $kunde, string $zurueck): ?string
    {
        $antwort = $this->anfrage()->asForm()->post($this->adresse('billing_portal/sessions'), [
            'customer' => $kunde,
            'return_url' => $zurueck,
        ]);

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

    private function adresse(string $pfad): string
    {
        return rtrim((string) config('services.stripe.url'), '/').'/v1/'.$pfad;
    }

    private function anfrage(): PendingRequest
    {
        return Http::withToken((string) config('services.stripe.key'))
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
