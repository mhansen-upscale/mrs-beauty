<?php

declare(strict_types=1);

namespace App\Abrechnung;

use App\Abrechnung\Stripe\Stripeclient;
use App\Enums\MessageCostCategory;
use App\Models\Message;
use App\Models\WaitlistOffer;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Antworten im offenen Service-Fenster -- gezaehlt, bepreist, abgerechnet
 * (Entscheidung B14, 26.09.2026).
 *
 * **Warum ueberhaupt.** Ob Meta ab dem 01.10.2026 auch Antworten im Fenster
 * berechnet, sagen die eigenen Unterlagen unterschiedlich. Statt zu raten,
 * zaehlt das Produkt sie ab jetzt und berechnet sie mit einem Preis aus der
 * Umgebung, vorerst null Euro. Steigt er, fliesst er ohne Codeaenderung in
 * die naechste Rechnung.
 *
 * **Gesperrt wird eine Antwort nie** (B12). Sie zaehlt nicht gegen das
 * Kontingent, sondern wird nachtraeglich abgerechnet -- eine Praxis darf nie
 * daran gehindert werden, einer Patientin zu antworten.
 *
 * **Ein Sammelposten je Monat, keine Einzelposten** (B11): auf der Rechnung
 * steht die Menge, nicht jede Nachricht.
 */
final class Servicefensterabrechnung
{
    public function __construct(
        private readonly Kontingente $kontingente,
        private readonly Nutzungsuebersicht $nutzung,
        private readonly Stripeclient $stripe,
    ) {}

    /** Was eine Antwort im Fenster gerade kostet, in Zehntel-Cent. */
    public function preis(): int
    {
        return max(0, (int) config('mrs.billing.service_window.price_tenth_cents', 0));
    }

    /**
     * Haelt den Preis an der Nachricht fest, sobald Meta die Kategorie meldet.
     *
     * **Der Preis zum Zeitpunkt der Nachricht**, nicht der zum Zeitpunkt der
     * Rechnung: wer ihn erhoeht, erhoeht ihn ab dann. Deshalb auch nur, wenn
     * noch keiner dasteht -- eine doppelte Rueckmeldung bepreist nicht neu.
     *
     * Gespeichert wird die Nachricht vom Aufrufer.
     */
    public function bepreise(Message $nachricht): void
    {
        if ($nachricht->cost_category !== MessageCostCategory::Service || $nachricht->istEingehend()) {
            return;
        }

        if ($nachricht->charge_tenth_cents !== null) {
            return;
        }

        $nachricht->charge_tenth_cents = $this->preis();

        $this->vermerkeAmAngebot($nachricht);
    }

    /**
     * Rechnet einen abgeschlossenen Monat der geltenden Praxis ab.
     *
     * @param  string  $monat  `Y-m`
     * @return string Was geschah -- fuer das Protokoll des Laufs
     *
     * @throws RuntimeException wenn Stripe nicht antwortet; der Auftrag wird
     *                          dann wiederholt und der Monat nicht vermerkt
     */
    public function rechneAb(string $monat): string
    {
        $abo = $this->kontingente->abo();

        if ($abo->service_window_billed_period !== null && $abo->service_window_billed_period >= $monat) {
            return 'bereits abgerechnet';
        }

        $von = CarbonImmutable::parse($monat.'-01 00:00:00', 'UTC');
        $bis = $von->endOfMonth();

        $menge = $this->nutzung->servicefenster($von, $bis);
        $betragCent = (int) round($this->nutzung->servicefensterBetrag($von, $bis) / 10);

        if ($betragCent <= 0) {
            // Null Euro ist eine Aussage, keine Luecke: der Monat ist erledigt.
            $abo->service_window_billed_period = $monat;
            $abo->save();

            return 'nichts zu berechnen';
        }

        // Ohne laufendes Abo gibt es keine Rechnung, an die der Posten
        // gehoeren koennte. Die Testphase ist frei.
        if (! is_string($abo->stripe_customer_id) || ! is_string($abo->stripe_subscription_id) || ! $this->stripe->angebunden()) {
            return 'kein laufendes Abo';
        }

        $praxis = $this->kontingente->praxis();

        $kennung = $this->stripe->rechnungsposten(
            kunde: $abo->stripe_customer_id,
            abo: $abo->stripe_subscription_id,
            betragCent: $betragCent,
            beschreibung: sprintf(
                'WhatsApp-Antworten im Service-Fenster, %s: %d %s',
                $von->translatedFormat('F Y'),
                $menge,
                $menge === 1 ? 'Antwort' : 'Antworten',
            ),
            // Stripe haelt den Schluessel 24 Stunden; fuer alles danach steht
            // der Monat am Abo.
            idempotenz: 'servicefenster-'.($praxis->uuid ?? 'unbekannt').'-'.$monat,
        );

        if ($kennung === null) {
            throw new RuntimeException('Stripe hat den Rechnungsposten nicht angenommen.');
        }

        $abo->service_window_billed_period = $monat;
        $abo->save();

        return 'abgerechnet: '.$menge.' Antworten, '.$betragCent.' Cent';
    }

    /**
     * Die offene Stelle aus WP-25: `cost_micros` stand, die Quelle fehlte.
     *
     * Ein Angebot geht als Antwort im Fenster hinaus und kostet damit genau
     * das, was diese Nachricht kostet. Zugeordnet wird ueber den
     * Idempotenzschluessel, den der Vergabelauf vergibt.
     */
    private function vermerkeAmAngebot(Message $nachricht): void
    {
        $schluessel = $nachricht->idempotency_key;

        if (! is_string($schluessel) || ! str_starts_with($schluessel, 'warteliste-')) {
            return;
        }

        $kennung = substr($schluessel, strlen('warteliste-'));

        if (! Uuid::isCanonical($kennung)) {
            return;
        }

        $angebot = WaitlistOffer::query()->whereUuid($kennung)->first();

        if (! $angebot instanceof WaitlistOffer || $angebot->cost_micros !== null) {
            return;
        }

        // Ein Zehntel-Cent sind tausend Mikro-Euro.
        $angebot->cost_micros = (int) $nachricht->charge_tenth_cents * 1000;
        $angebot->save();
    }
}
