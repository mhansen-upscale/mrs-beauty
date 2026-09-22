<?php

declare(strict_types=1);

namespace App\Attribution;

use App\Models\AttributionTouch;
use App\Models\Contact;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Schreibt Touches und verknuepft sie rueckwirkend.
 *
 * **Weniger als moeglich.** Vom Verweis bleibt der Host, von der Adresse der
 * Pfad. Der Abfrageteil traegt, was jemand angehaengt hat -- im Zweifel eine
 * Behandlung -- und sobald der Touch mit einem Kontakt verknuepft ist, stuende
 * sie unverschluesselt daneben (Regel 3). Was gebraucht wird, steht in
 * eigenen Spalten.
 */
final class Beruehrungen
{
    public function __construct(private readonly Besucherkennung $besucher) {}

    /**
     * Haelt einen Aufruf fest. Ohne Einwilligung geschieht nichts.
     */
    public function erfasse(Request $anfrage, ?CarbonImmutable $jetzt = null): ?AttributionTouch
    {
        $besucher = $this->besucher->kennung($anfrage);

        if ($besucher === null) {
            return null;
        }

        $touch = new AttributionTouch;
        $touch->visitor_id = $besucher;
        $touch->click_id = $this->wert($anfrage->query('fbclid'), 255);

        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $teil) {
            $touch->{'utm_'.$teil} = $this->wert($anfrage->query('utm_'.$teil), 191);
        }

        // Die Kennungen setzt WP-27 selbst in die Adressen seiner Anzeigen.
        $touch->campaign_external_id = $this->wert($anfrage->query('mrs_campaign'), 64);
        $touch->adset_external_id = $this->wert($anfrage->query('mrs_adset'), 64);
        $touch->ad_external_id = $this->wert($anfrage->query('mrs_ad'), 64);

        // **Nur der Pfad.** Der Abfrageteil bleibt draussen.
        $touch->landing_path = mb_substr($anfrage->getPathInfo(), 0, 255);

        $touch->referrer_host = $this->host($anfrage->headers->get('referer'), $anfrage->getHost());
        $touch->occurred_at = $jetzt ?? CarbonImmutable::now();
        $touch->save();

        return $touch;
    }

    /**
     * Verknuepft **alle** Touches dieses Besuchers mit Kontakt und Lead.
     *
     * Rueckwirkend, weil die Kette erst mit dem Lead entsteht: vorher gibt es
     * nur eine Zufalls-ID, hinterher einen Menschen.
     */
    public function verknuepfe(string $besucher, Contact $kontakt, ?Lead $lead = null): int
    {
        $werte = ['contact_id' => $kontakt->getKey()];

        if ($lead instanceof Lead) {
            $werte['lead_id'] = $lead->getKey();
        }

        return AttributionTouch::query()
            ->fuerBesucher($besucher)
            ->update($werte);
    }

    private function wert(mixed $roh, int $laenge): ?string
    {
        if (! is_string($roh)) {
            return null;
        }

        $roh = trim($roh);

        return $roh === '' ? null : mb_substr($roh, 0, $laenge);
    }

    /**
     * Vom Verweis bleibt der Host -- und der eigene zaehlt nicht.
     *
     * Woher jemand kam, ist eine Quelle; welche Seite genau, ist eine Aussage
     * ueber ihn.
     *
     * **Der eigene Host ist keine Quelle.** Gefunden im Durchlauf gegen die
     * Entwicklungsumgebung: der zweite Aufruf der Buchungsseite trug
     * `mrs-beauty.test` als Verweis -- und haette damit als Touch **mit**
     * Quelle gegolten. Last-Non-Direct haette dann nie einen Direktaufruf
     * uebersprungen, weil es keinen mehr gegeben haette.
     */
    private function host(?string $verweis, string $eigener): ?string
    {
        if (! is_string($verweis) || $verweis === '') {
            return null;
        }

        $host = parse_url($verweis, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || mb_strtolower($host) === mb_strtolower($eigener)) {
            return null;
        }

        return mb_substr($host, 0, 191);
    }
}
