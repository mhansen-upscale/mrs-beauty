<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Buchung\Buchungsdialog;
use App\Agent\Buchung\Dialogantwort;
use App\Agent\Guardrails\Alarm;
use App\Agent\Guardrails\Kennzeichnung;
use App\Agent\Guardrails\Nachpruefung;
use App\Agent\Guardrails\Schutz;
use App\Agent\Guardrails\Weiche;
use App\Enums\AgentAction;
use App\Enums\AgentIntent;
use App\Enums\AgentMode;
use App\Enums\GuardrailHit;
use App\Kanaele\Nachrichtenversand;
use App\Leads\Leadverwaltung;
use App\Models\AgentDialog;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\QueryException;

/**
 * Ein Durchlauf des Agenten ueber eine eingehende Nachricht.
 *
 * Der Ablauf aus docs/fachlogik/agent.md, so weit dieses Paket reicht:
 *
 * ```
 * 2. Modus pruefen: off -> Ende
 * 3. Klassifikation
 * 6. Entwurf -- nur, wenn die Absicht ihn zulaesst
 * 8. In das Eingabefeld schreiben (suggest). Gesendet wird nichts.
 * 9. agent_run protokollieren
 * ```
 *
 * **Schritt 4, 5 und 7 fehlen hier und kommen in WP-23.** Was dieses Paket
 * baut, kann nichts senden: `auto` ist gesperrt, und ein Vorschlag geht erst
 * hinaus, wenn ihn jemand liest und abschickt.
 */
final class Agentenlauf
{
    public function __construct(
        private readonly Einordner $einordner,
        private readonly Entwerfer $entwerfer,
        private readonly Sprachmodell $modell,
        private readonly Weiche $weiche,
        private readonly Nachpruefung $nachpruefung,
        private readonly Schutz $schutz,
        private readonly Alarm $alarm,
        private readonly Kontingent $kontingent,
        private readonly Buchungsdialog $dialog,
        private readonly Kennzeichnung $kennzeichnung,
        private readonly Nachrichtenversand $versand,
        private readonly Leadverwaltung $leads,
    ) {}

    /**
     * @return AgentRun|null Null, wenn es zu dieser Nachricht schon einen gibt
     */
    public function fuer(Message $nachricht): ?AgentRun
    {
        if (! $nachricht->istEingehend()) {
            // Was wir selbst geschrieben haben, ordnet niemand ein.
            return null;
        }

        $gespraech = $nachricht->conversation;
        $lauf = $this->beginne($nachricht);

        if (! $lauf instanceof AgentRun) {
            // **Genau ein Lauf je Nachricht.** Ein Auftrag, der nach einem
            // Deploy erneut laeuft, ist der Normalfall (A13) -- ein zweiter
            // Vorschlag waere zweimal dasselbe im Eingabefeld.
            return null;
        }

        if ($gespraech->agent_mode === AgentMode::Off) {
            // Kein Aufruf, keine Kosten, kein Text. Aus heisst aus.
            return $this->halteFest($lauf, AgentAction::Skipped, grund: 'agent_off');
        }

        // **Der Not-Aus, vor allem anderen** (Entscheidung G8): Installation,
        // Mandant, Pause. Geprueft bei jedem Durchlauf, nicht beim Start des
        // Gespraechs -- ein Schalter, der erst beim naechsten Dialog greift,
        // ist im Ernstfall keiner.
        $notAus = $this->schutz->notAus($gespraech);

        if ($notAus instanceof GuardrailHit) {
            // Ein laufender Dialog ueberlebt den Not-Aus nicht -- und sein
            // Hold auch nicht.
            $this->dialog->beende($gespraech);

            return $this->halteFest(
                $lauf,
                AgentAction::Skipped,
                grund: $notAus->value,
                treffer: [$notAus],
            );
        }

        // **Vor dem Aufruf, nicht danach** (Entscheidung G11): ein Lauf, der
        // erst hinterher auffaellt, ist schon bezahlt.
        if ($this->kontingent->erschoepft()) {
            return $this->halteFest(
                $lauf,
                AgentAction::Skipped,
                grund: GuardrailHit::BudgetExhausted->value,
                treffer: [GuardrailHit::BudgetExhausted],
            );
        }

        if (! $this->modell->angebunden()) {
            // Ohne Modell wird nichts erfunden -- und es steht am Lauf,
            // statt still zu bleiben.
            return $this->halteFest($lauf, AgentAction::Failed, fehler: 'no_model');
        }

        $begonnen = (float) hrtime(true);
        $verbrauch = new Verbrauch;

        try {
            $einordnung = $this->einordner->ordneEin($nachricht, $verbrauch);
        } catch (ModellNichtErreichbar $ausnahme) {
            return $this->halteFest(
                $lauf,
                AgentAction::Failed,
                fehler: $ausnahme->kurzgrund,
                dauer: $this->dauer($begonnen),
                verbrauch: $verbrauch,
            );
        }

        $lauf->intent = $einordnung->absicht;
        $lauf->confidence = $einordnung->sicherheit;
        $lauf->setzeEntitaeten($einordnung->entitaeten());
        $lauf->model = (string) config('mrs.agent.model');

        // **Die harte Weiche, vor jeder Textgenerierung** (Schritt 4).
        // Nicht ueberstimmbar: das Komplikationssignal ist eine
        // Wortstammsuche und gilt unabhaengig von der erkannten Absicht.
        $treffer = $this->weiche->pruefe($nachricht, $einordnung);

        // Schritt 5: zu unsicher ist auch ein Grund, einen Menschen zu
        // fragen.
        if ($this->schutz->zuUnsicher($einordnung->sicherheit)) {
            $treffer[] = GuardrailHit::LowConfidence;
        }

        if ($this->schutz->zuVieleAutomatischeAntworten($gespraech)) {
            $treffer[] = GuardrailHit::TooManyAutoReplies;
        }

        if ($treffer !== []) {
            return $this->uebergib($lauf, $gespraech, $treffer, $begonnen, $verbrauch);
        }

        // **Der Buchungsdialog** (WP-24, Schritt 6). Er formuliert selbst --
        // die Saetze stehen im Produkt, nicht im Modell. Ein Modell, das hier
        // frei formulierte, koennte einen Termin zusagen, den es nicht gibt.
        if ($this->gehoertInDenDialog($gespraech, $einordnung)) {
            // **Nur im Modus `auto` wirkt er.** Im Vorschlagsmodus rechnet er
            // aus, was er sagen wuerde -- und haelt keinen Slot, bucht keinen
            // Termin und vermerkt keine Einwilligung fuer einen Satz, den
            // niemand abgeschickt hat.
            $antwort = $this->dialog->fuehre(
                $gespraech,
                $nachricht,
                $einordnung,
                wirksam: $gespraech->agent_mode === AgentMode::Auto,
            );

            return $this->liefere($lauf, $gespraech, $antwort, $begonnen, $verbrauch);
        }

        try {
            $entwurf = $this->entwerfer->entwirf($gespraech, $einordnung, $verbrauch);
        } catch (ModellNichtErreichbar $ausnahme) {
            return $this->halteFest(
                $lauf,
                AgentAction::Failed,
                fehler: $ausnahme->kurzgrund,
                dauer: $this->dauer($begonnen),
                verbrauch: $verbrauch,
            );
        }

        if ($entwurf === '') {
            return $this->halteFest($lauf, AgentAction::Failed, fehler: 'empty', dauer: $this->dauer($begonnen), verbrauch: $verbrauch);
        }

        return $this->liefere($lauf, $gespraech, Dialogantwort::sagt($entwurf), $begonnen, $verbrauch);
    }

    /**
     * Prueft die Antwort nach -- und schickt sie, wenn sie hinaus darf.
     *
     * **Der einzige Ort, an dem etwas das Haus verlaesst.** Bis WP-23 ging
     * hier nie etwas hinaus; seit WP-24 im Modus `auto` schon, und dann gilt
     * alles davor zuerst: harte Weiche, Konfidenz, Not-Aus, Kontingent -- und
     * die Nachpruefung aus Schritt 7, auch fuer einen Text aus dem Produkt.
     */
    private function liefere(
        AgentRun $lauf,
        Conversation $gespraech,
        Dialogantwort $antwort,
        float $begonnen,
        Verbrauch $verbrauch,
    ): AgentRun {
        // **Die zweite Verteidigungslinie** (Schritt 7), bevor der Text
        // jemand zu sehen bekommt: ein Vorschlag im Eingabefeld wird
        // irgendwann abgeschickt.
        if (is_string($antwort->text)) {
            $beanstandet = $this->nachpruefung->pruefe($antwort->text);

            if ($beanstandet !== []) {
                return $this->uebergib($lauf, $gespraech, $beanstandet, $begonnen, $verbrauch);
            }
        }

        if ($antwort->uebergabe instanceof GuardrailHit) {
            // Ein Text darf trotzdem hinaus -- er stammt aus dem Produkt und
            // sagt der Person, dass sich jemand meldet.
            if (is_string($antwort->text) && $gespraech->agent_mode === AgentMode::Auto) {
                $this->sende($gespraech, $antwort->text);
            }

            return $this->uebergib($lauf, $gespraech, [$antwort->uebergabe], $begonnen, $verbrauch);
        }

        if (! is_string($antwort->text) || $antwort->text === '') {
            return $this->halteFest($lauf, AgentAction::Failed, fehler: 'empty', dauer: $this->dauer($begonnen), verbrauch: $verbrauch);
        }

        if ($gespraech->agent_mode !== AgentMode::Auto) {
            $lauf->suggestion = $antwort->text;

            // Geschrieben wird ins Eingabefeld, nicht in den Kanal. Ein
            // Mensch liest, aendert und schickt.
            return $this->halteFest($lauf, AgentAction::Suggested, dauer: $this->dauer($begonnen), verbrauch: $verbrauch);
        }

        // **Kennzeichnung bei der ersten automatischen Antwort** (G6,
        // Transparenzpflicht nach EU AI Act). Sie wird **vor** dem Vermerken
        // des Laufs gebildet -- danach gilt diese Konversation als
        // gekennzeichnet.
        $hinaus = $this->kennzeichnung->ergaenze($antwort->text, $gespraech);

        $this->sende($gespraech, $hinaus);

        $lauf->suggestion = $hinaus;

        return $this->halteFest($lauf, AgentAction::Answered, dauer: $this->dauer($begonnen), verbrauch: $verbrauch);
    }

    private function sende(Conversation $gespraech, string $text): void
    {
        // Ueber dieselbe Warteschlange wie jede andere Nachricht (Regel 4):
        // der Agent bekommt keinen eigenen Weg nach draussen.
        $this->versand->stelleEin($gespraech, $text);

        // Eine automatische Antwort ist eine Reaktion der Praxis (WP-17) --
        // der Kanal der Praxis, im Namen der Praxis.
        $this->leads->beiAntwort($gespraech);
    }

    /**
     * Gehoert diese Nachricht in den Buchungsdialog?
     *
     * Entweder sie fragt nach einem Termin -- oder ein Vorgang laeuft schon.
     * Wer mitten im Dialog "ja" schreibt, meint die letzte Frage und nicht
     * eine neue Absicht.
     */
    private function gehoertInDenDialog(Conversation $gespraech, Klassifikation $einordnung): bool
    {
        // Absagen und Verschieben laufen ueber denselben Automaten (offen
        // seit WP-24) -- mit Rueckfrage, und nur fuer genau einen Termin.
        if (in_array($einordnung->absicht, [AgentIntent::BookingRequest, AgentIntent::CancelRequest, AgentIntent::RescheduleRequest], true)) {
            return true;
        }

        $dialog = AgentDialog::query()->where('conversation_id', $gespraech->getKey())->first();

        // Ein abgeschlossener Vorgang -- gebucht oder auf der Warteliste --
        // nimmt keine Antwort mehr als Fortsetzung: "danke!" ist dann keine
        // Antwort auf die letzte Frage.
        return $dialog instanceof AgentDialog && ! $dialog->state->abgeschlossen();
    }

    /**
     * Uebergibt an einen Menschen: protokollieren, alarmieren, pausieren.
     *
     * **Kein Text.** Auch kein verworfener -- was beanstandet wurde, steht
     * nicht im Eingabefeld, sonst wird es irgendwann abgeschickt.
     *
     * @param  list<GuardrailHit>  $treffer
     */
    private function uebergib(
        AgentRun $lauf,
        Conversation $gespraech,
        array $treffer,
        float $begonnen,
        Verbrauch $verbrauch,
    ): AgentRun {
        $erste = $treffer[0];

        // **Ein Hold ohne Freigabe blockiert einen echten Termin.** Jede
        // Rueckkehr aus dem Dialog gibt ihn frei -- auch die ueber eine
        // Eskalation (Testfall 20).
        $this->dialog->beende($gespraech);

        foreach ($treffer as $regel) {
            if ($regel->alarmiert()) {
                // Entscheidung G4: sofort, nicht mit dem naechsten Bericht.
                $this->alarm->schlage($gespraech, $regel);
            }

            if ($regel->pausiert()) {
                $this->schutz->pausiere($gespraech);
            }
        }

        return $this->halteFest(
            $lauf,
            AgentAction::Escalated,
            grund: $erste->value,
            dauer: $this->dauer($begonnen),
            verbrauch: $verbrauch,
            treffer: $treffer,
        );
    }

    /**
     * Legt den Lauf an -- oder nicht, wenn es ihn schon gibt.
     */
    private function beginne(Message $nachricht): ?AgentRun
    {
        try {
            $lauf = new AgentRun;
            $lauf->conversation_id = $nachricht->conversation_id;
            $lauf->message_id = $nachricht->getKey();
            $lauf->action = AgentAction::Skipped;
            $lauf->save();

            return $lauf;
        } catch (QueryException $ausnahme) {
            if (str_contains($ausnahme->getMessage(), 'agentenlauf_nachricht_unique')) {
                return null;
            }

            throw $ausnahme;
        }
    }

    /**
     * @param  list<GuardrailHit>  $treffer
     */
    private function halteFest(
        AgentRun $lauf,
        AgentAction $aktion,
        ?string $grund = null,
        ?string $fehler = null,
        ?int $dauer = null,
        ?Verbrauch $verbrauch = null,
        array $treffer = [],
    ): AgentRun {
        $lauf->action = $aktion;
        $lauf->escalation_reason = $grund;
        $lauf->guardrails = $treffer === []
            ? null
            : array_map(fn (GuardrailHit $regel): string => $regel->value, $treffer);
        $lauf->failure = $fehler;
        $lauf->duration_ms = $dauer;

        if ($verbrauch instanceof Verbrauch) {
            $lauf->input_tokens = $verbrauch->eingabe;
            $lauf->output_tokens = $verbrauch->ausgabe;
            $lauf->cost_tenth_cents = $verbrauch->kostenZehntelCent((string) $lauf->model);
        }

        $lauf->save();

        return $lauf;
    }

    private function dauer(float $begonnen): int
    {
        return (int) round(((float) hrtime(true) - $begonnen) / 1_000_000);
    }
}
