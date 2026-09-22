<?php

declare(strict_types=1);

namespace App\Abrechnung;

use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Models\AdSuggestion;
use App\Models\AgentRun;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;

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
     * Nur, was Geld kostet.
     *
     * **Die Kategorie kommt vom Anbieter** (WP-20a) und wird nie geschaetzt.
     * Was keine hat, ist noch unbekannt -- und wird deshalb nicht gezaehlt:
     * eine Rechnung auf Verdacht ist schlimmer als eine, die nachlaeuft.
     */
    public function kostenpflichtige(CarbonImmutable $von, CarbonImmutable $bis): int
    {
        return Message::query()
            ->where('direction', MessageDirection::Outbound->value)
            ->whereNotNull('cost_category')
            ->where('cost_category', '!=', MessageCostCategory::None->value)
            ->whereBetween('created_at', [$von, $bis])
            ->count();
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
        // Datei und eine zweite Position -- sie kostet dasselbe wie die
        // erste. Der Entwurf als Zaehleinheit haette das Nacherzeugen
        // verschenkt.
        return Attachment::query()
            ->where('attachable_type', AdSuggestion::class)
            ->whereBetween('created_at', [$von, $bis])
            ->count();
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
