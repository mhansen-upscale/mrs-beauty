<?php

declare(strict_types=1);

namespace App\Abrechnung;

use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Models\AdSuggestionImage;
use App\Models\AgentRun;
use App\Models\Message;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Was eine Praxis in einem Monat verbraucht hat.
 *
 * **Gerechnet, nicht zweitgefuehrt.** Es gibt keine `usage_records`-Tabelle:
 * was Geld kostet, steht schon in den Fachtabellen -- die kostenpflichtige
 * Nachricht in `messages`, der Assistenzlauf in `agent_runs`, das Angebot in
 * `waitlist_offers`. Eine zweite Zeile je Vorgang waere ein zweiter Ort fuer
 * dieselbe Zahl, und zwei Orte gehen irgendwann auseinander.
 *
 * Das ist zugleich Entscheidung **B7**: "Nutzungserfassung beim Versand jeder
 * kostenpflichtigen Nachricht, nicht nachgelagert" -- die Erfassung **ist**
 * die Nachrichtenzeile, und die entsteht beim Versand.
 */
final class Nutzungsuebersicht
{
    /**
     * @return array<string, mixed>
     */
    public function fuerMonat(?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();
        $von = $jetzt->startOfMonth();
        $bis = $jetzt->endOfMonth();

        return [
            'zeitraum' => $jetzt->format('Y-m'),
            'nachrichten' => $this->nachrichten($von, $bis),
            'kostenpflichtigeNachrichten' => $this->kostenpflichtige($von, $bis),
            'servicefenster' => $this->servicefenster($von, $bis),
            'agentenlaeufe' => $this->agentenlaeufe($von, $bis),
            'angebote' => $this->angebote($von, $bis),

            // Intern in Zehntel-Cent -- angezeigt wird es nicht (B11).
            'modellkosten_zehntel_cent' => $this->modellkosten($von, $bis),
        ];
    }

    /** Alles, was hinausging -- kostenlos wie kostenpflichtig. */
    public function nachrichten(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return Message::query()
            ->where('direction', MessageDirection::Outbound->value)
            ->whereBetween('created_at', [$von, $bis])
            ->count();
    }

    /**
     * Was gegen das Kontingent zaehlt: Templates.
     *
     * **Die Kategorie kommt vom Anbieter** (WP-20a) und wird nie geschaetzt.
     * Was keine hat, ist noch unbekannt -- und wird deshalb nicht gezaehlt:
     * eine Rechnung auf Verdacht ist schlimmer als eine, die nachlaeuft.
     *
     * **Eine Antwort im Service-Fenster zaehlt hier nicht** (B12). Bis zum
     * 26.09.2026 tat sie es doch: Meta meldet sie als `service`, und nur
     * `none` war ausgenommen. Eine Praxis, die viel antwortet, haette damit
     * ihr eigenes Template-Kontingent aufgebraucht. Antworten laufen jetzt
     * ueber `servicefenster()` (B14).
     */
    public function kostenpflichtige(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return Message::query()
            ->where('direction', MessageDirection::Outbound->value)
            ->whereNotNull('cost_category')
            ->whereNotIn('cost_category', [MessageCostCategory::None->value, MessageCostCategory::Service->value])
            ->whereBetween('created_at', [$von, $bis])
            ->count();
    }

    /**
     * Antworten im offenen Service-Fenster (Entscheidung B14).
     *
     * **Gezaehlt, nie gesperrt.** Was sie kosten, steht an jeder einzelnen
     * (`charge_tenth_cents`) -- mit dem Preis, der galt, als Meta die
     * Kategorie meldete.
     */
    public function servicefenster(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return $this->antwortenImFenster($von, $bis)->count();
    }

    /** Was die Antworten im Fenster zusammen kosten, in Zehntel-Cent. */
    public function servicefensterBetrag(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return (int) $this->antwortenImFenster($von, $bis)->sum('charge_tenth_cents');
    }

    /**
     * @return Builder<Message>
     */
    private function antwortenImFenster(CarbonImmutable $von, CarbonImmutable $bis): Builder
    {
        return Message::query()
            ->where('direction', MessageDirection::Outbound->value)
            ->where('cost_category', MessageCostCategory::Service->value)
            ->whereBetween('created_at', [$von, $bis]);
    }

    public function agentenlaeufe(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return AgentRun::query()
            ->whereNotNull('cost_tenth_cents')
            ->where('cost_tenth_cents', '>', 0)
            ->whereBetween('created_at', [$von, $bis])
            ->count();
    }

    /**
     * Erzeugte Anzeigenbilder (WP-31).
     *
     * **Gezaehlt wird am Entwurf, nicht an der Datei.** Ein Bild, das
     * erzeugt und dann geloescht wurde, hat trotzdem Geld gekostet -- wer an
     * der Datei zaehlt, verschenkt es rueckwirkend.
     */
    public function bilder(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        // **Gezaehlt wird jede erzeugte Grafik, nicht jeder Entwurf.** Wer
        // zu einem Entwurf eine zweite erzeugen laesst, bekommt eine zweite
        // Position -- sie kostet dasselbe wie die erste. Der Entwurf als
        // Zaehleinheit haette das Nacherzeugen verschenkt.
        //
        // **Eine Grafik ist ein Formatsatz, nicht eine Datei** (B13,
        // angepasst am 27.09.2026). Die drei Formate sind Pflicht jeder
        // Anzeige; je Datei gezaehlt, wuerden aus 30 enthaltenen Grafiken
        // stillschweigend 10 Anzeigen. Ein Satz, von dem nur ein Format
        // ankam, zaehlt trotzdem -- er hat Geld gekostet.
        return AdSuggestionImage::query()
            ->whereBetween('created_at', [$von, $bis])
            ->distinct()
            ->count('batch');
    }

    public function angebote(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return WaitlistOffer::query()->whereBetween('created_at', [$von, $bis])->count();
    }

    private function modellkosten(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return (int) AgentRun::query()->whereBetween('created_at', [$von, $bis])->sum('cost_tenth_cents');
    }
}
