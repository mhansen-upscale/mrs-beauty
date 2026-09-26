<?php

declare(strict_types=1);

namespace App\Agent\Buchung;

use App\Agent\Behandlerzuordnung;
use App\Agent\Klassifikation;
use App\Datenschutz\Einwilligungen;
use App\Enums\AgentIntent;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\BookingState;
use App\Enums\CancellationReason;
use App\Enums\ConsentType;
use App\Enums\GuardrailHit;
use App\Enums\HoldPurpose;
use App\Enums\WaitlistStatus;
use App\Kontakte\Kontaktsuche;
use App\Models\AgentDialog;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Location;
use App\Models\Message;
use App\Models\Practitioner;
use App\Models\SlotHold;
use App\Models\Treatment;
use App\Models\WaitlistEntry;
use App\Termine\TerminNichtAenderbar;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\SlotNichtVerfuegbar;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Der Buchungsdialog (docs/fachlogik/agent.md, Schritt 6).
 *
 * **Ein Zustandsautomat an der Konversation**, kein freies Gespraech. Die
 * Saetze stehen in Dialogtexte, die Deutung kurzer Antworten in
 * Antwortdeutung -- das Sprachmodell liefert Absicht und Entitaeten und
 * sonst nichts.
 *
 * Vier Regeln der Spezifikation, jede an einer Stelle:
 *
 * - **Hoechstens drei Klaerungsversuche je Zustand**, dann Uebergabe.
 * - **Slot-Hold ab der Auswahl**, TTL aus der Konfiguration. Abgelaufen
 *   heisst neu vorschlagen, nicht scheitern.
 * - **Ein Vorgang je Konversation** (G9) -- der Unique-Index auf
 *   `agent_dialogs`.
 * - **Keine Sackgasse**: ohne passenden Slot wird nicht abgebrochen.
 */
final class Buchungsdialog
{
    public function __construct(
        private readonly Dialogtexte $texte,
        private readonly Antwortdeutung $deutung,
        private readonly Verfuegbarkeit $verfuegbarkeit,
        private readonly SlotHalter $halter,
        private readonly Terminplaner $planer,
        private readonly Einwilligungen $einwilligungen,
        private readonly Kontaktsuche $kontakte,
        private readonly Behandlerzuordnung $zuordnung,
    ) {}

    /**
     * Fuehrt den Dialog einen Schritt weiter.
     *
     * **`$wirksam` trennt Tun von Vorschlagen.** Im Modus `suggest` geht kein
     * Satz hinaus -- also darf der Dialog auch nichts tun, was von einem Satz
     * abhinge, den niemand gelesen hat: keinen Slot halten, keine
     * Einwilligung vermerken, keinen Termin buchen. Er rechnet dann nur aus,
     * was er **sagen wuerde**.
     *
     * Umgesetzt als Transaktion, die zurueckgerollt wird, und nicht als
     * Kette von Abfragen an jeder Seiteneffektstelle: so entwischt auch ein
     * Seiteneffekt nicht, den jemand spaeter ergaenzt, ohne an diesen Modus
     * zu denken.
     */
    public function fuehre(
        Conversation $gespraech,
        Message $nachricht,
        Klassifikation $einordnung,
        bool $wirksam = true,
    ): Dialogantwort {
        if ($wirksam) {
            return $this->lauf($gespraech, $nachricht, $einordnung);
        }

        DB::beginTransaction();

        try {
            return $this->lauf($gespraech, $nachricht, $einordnung);
        } finally {
            DB::rollBack();
        }
    }

    private function lauf(Conversation $gespraech, Message $nachricht, Klassifikation $einordnung): Dialogantwort
    {
        $dialog = $this->vorgang($gespraech);

        // **Ein Termin, der schon steht** (offen seit WP-24): absagen oder
        // verschieben. Nicht mitten in einer Buchung -- wer einen Slot haelt,
        // meint mit "lieber doch nicht" den Slot und nicht einen anderen
        // Termin.
        if ($this->willAendern($einordnung) && ! $dialog->state->haeltSlot()) {
            return $this->aenderungBeginnen($dialog, $gespraech, $einordnung->absicht);
        }

        if ($dialog->state->aendertTermin()) {
            return $this->schritt($dialog, $gespraech, $nachricht);
        }

        // Nach einem abgeschlossenen Vorgang beginnt eine neue Terminanfrage
        // einen neuen -- es sei denn, der gebuchte Termin steht noch. Dann
        // gilt G9.
        if ($dialog->state->abgeschlossen()
            && $einordnung->absicht === AgentIntent::BookingRequest
            && ! $this->terminDesVorgangsSteht($dialog)) {
            $this->neuerVorgang($dialog);
        }

        // **Ein Termin je Vorgang** (G9): ein zweiter Versuch fuehrt zur
        // Rueckfrage, nicht zu einem zweiten Termin.
        if ($dialog->appointment_id !== null) {
            return $this->rueckfrageZumBestehenden($dialog);
        }

        $this->uebernimm($dialog, $einordnung);

        // **Genannt, aber nicht zu finden:** fragen, nicht raten -- und auch
        // nicht bei jemand anderem vorschlagen, als waere nichts gesagt worden.
        if ($einordnung->behandlerUnklar && $dialog->requested_practitioner_id === null) {
            $this->gibHoldFrei($dialog);

            return $this->frage($dialog, BookingState::BehandlerKlaeren, $this->texte->behandlerFragen($this->behandlerFuer($dialog)));
        }

        // Ein abgelaufener Hold ist kein Fehler, sondern ein neuer Vorschlag.
        $this->pruefeHold($dialog);

        return $this->schritt($dialog, $gespraech, $nachricht);
    }

    /** Gibt einen gehaltenen Slot frei -- bei Uebergabe und bei Abbruch. */
    public function beende(Conversation $gespraech): void
    {
        $dialog = AgentDialog::query()->where('conversation_id', $gespraech->getKey())->first();

        if (! $dialog instanceof AgentDialog) {
            return;
        }

        $this->gibHoldFrei($dialog);
    }

    private function schritt(AgentDialog $dialog, Conversation $gespraech, Message $nachricht): Dialogantwort
    {
        return match ($dialog->state) {
            BookingState::Start, BookingState::TreatmentKlaeren => $this->behandlung($dialog),
            BookingState::StandortKlaeren => $this->standort($dialog, $nachricht),
            BookingState::BehandlerKlaeren => $this->behandlerWahl($dialog, $nachricht),
            BookingState::SlotsVorschlagen => $this->slots($dialog, $nachricht),
            BookingState::DatenErheben => $this->daten($dialog, $gespraech, $nachricht),
            BookingState::Einwilligung => $this->einwilligung($dialog, $gespraech, $nachricht),
            BookingState::Bestaetigen => $this->bestaetigen($dialog, $gespraech, $nachricht),
            BookingState::Gebucht => $this->rueckfrageZumBestehenden($dialog),
            BookingState::WartelisteAnbieten => $this->warteliste($dialog, $gespraech, $nachricht),

            // Eingetragen ist eingetragen. Was danach kommt, liest ein Mensch
            // -- ein zweiter Eintrag oder eine Buchung am Team vorbei waere
            // die falsche Antwort auf "und, gibt es schon was?".
            BookingState::AufWarteliste => Dialogantwort::uebergibt(GuardrailHit::LowConfidence),

            BookingState::AbsageBestaetigen => $this->absageBestaetigen($dialog, $nachricht),
            BookingState::VerschiebenVorschlagen => $this->verschiebenWahl($dialog, $nachricht),
            BookingState::VerschiebenBestaetigen => $this->verschiebenBestaetigen($dialog, $nachricht),
            BookingState::Geaendert => Dialogantwort::uebergibt(GuardrailHit::LowConfidence),
        };
    }

    /* Zustaende ------------------------------------------------------------ */

    private function behandlung(AgentDialog $dialog): Dialogantwort
    {
        if ($dialog->appointment_type_id !== null) {
            $dialog->wechsleNach(BookingState::StandortKlaeren);

            return $this->standortOhneFrage($dialog);
        }

        $behandlungen = Treatment::query()->aktiv()->orderBy('name')->get();

        if ($behandlungen->count() === 1) {
            // Eine Praxis mit einer Leistung muss nicht gefragt werden.
            $this->setzeBehandlung($dialog, $behandlungen->firstOrFail());

            return $this->standortOhneFrage($dialog);
        }

        return $this->frage($dialog, BookingState::TreatmentKlaeren, $this->texte->behandlungFragen($behandlungen->values()->all()));
    }

    private function standortOhneFrage(AgentDialog $dialog): Dialogantwort
    {
        $art = $this->art($dialog);

        if (! $art instanceof AppointmentType) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keineSlots());
        }

        $standorte = $art->locations()->where('is_active', true)->orderBy('name')->get();

        if ($dialog->location_id !== null || $standorte->count() <= 1) {
            if ($dialog->location_id === null && $standorte->count() === 1) {
                $dialog->location_id = $standorte->firstOrFail()->getKey();
                $dialog->save();
            }

            $dialog->wechsleNach(BookingState::SlotsVorschlagen);

            return $this->vorschlagen($dialog);
        }

        return $this->frage($dialog, BookingState::StandortKlaeren, $this->texte->standortFragen($standorte->values()->all()));
    }

    private function standort(AgentDialog $dialog, Message $nachricht): Dialogantwort
    {
        if ($dialog->location_id !== null) {
            $dialog->wechsleNach(BookingState::SlotsVorschlagen);

            return $this->vorschlagen($dialog);
        }

        $art = $this->art($dialog);
        $standorte = $art instanceof AppointmentType
            ? $art->locations()->where('is_active', true)->orderBy('name')->get()
            : Location::query()->where('is_active', true)->get();

        $gewaehlt = $standorte->first(fn (Location $standort): bool => str_contains(
            mb_strtolower((string) $nachricht->body),
            mb_strtolower($standort->name),
        ));

        if ($gewaehlt instanceof Location) {
            $dialog->location_id = $gewaehlt->getKey();
            $dialog->save();
            $dialog->wechsleNach(BookingState::SlotsVorschlagen);

            return $this->vorschlagen($dialog);
        }

        return $this->frage($dialog, BookingState::StandortKlaeren, $this->texte->standortFragen($standorte->values()->all()));
    }

    /**
     * Die Antwort auf "bei wem?" (behandler_klaeren).
     *
     * Ein Name aus der Liste wird zum Wunsch, "egal" zu keinem. Danach geht es
     * weiter, wo der Dialog ohne die Frage waere.
     */
    private function behandlerWahl(AgentDialog $dialog, Message $nachricht): Dialogantwort
    {
        if ($dialog->requested_practitioner_id === null) {
            $kandidaten = $this->behandlerFuer($dialog);
            $gewaehlt = $this->zuordnung->ausText((string) $nachricht->body, collect($kandidaten));

            if ($gewaehlt instanceof Practitioner) {
                $dialog->requested_practitioner_id = $gewaehlt->getKey();
                $dialog->save();
            } elseif (! $this->deutung->gleichgueltig($nachricht)) {
                return $this->frage($dialog, BookingState::BehandlerKlaeren, $this->texte->behandlerFragen($kandidaten));
            }
        }

        $dialog->wechsleNach(BookingState::Start);

        return $this->behandlung($dialog);
    }

    private function slots(AgentDialog $dialog, Message $nachricht): Dialogantwort
    {
        $gewaehlt = $this->deutung->wahl($nachricht, $dialog->angebote());

        if ($gewaehlt === null || $dialog->angebote() === []) {
            return $this->vorschlagen($dialog);
        }

        $vorschlag = $this->vorschlagZu($dialog, CarbonImmutable::parse($gewaehlt));

        if (! $vorschlag instanceof Slotvorschlag) {
            // Zwischen Angebot und Antwort hat ihn jemand genommen.
            return $this->vorschlagen($dialog);
        }

        $hold = $this->halter->halte($vorschlag, HoldPurpose::AgentDialog);

        $dialog->slot_hold_id = $hold->getKey();
        $dialog->practitioner_id = $vorschlag->behandler->getKey();
        $dialog->location_id = $vorschlag->standort->getKey();
        $dialog->save();

        $dialog->wechsleNach(BookingState::DatenErheben);

        $gespraech = $dialog->conversation;

        return $gespraech instanceof Conversation
            ? $this->daten($dialog, $gespraech, $nachricht, frisch: true)
            : Dialogantwort::uebergibt(GuardrailHit::LowConfidence);
    }

    private function vorschlagen(AgentDialog $dialog): Dialogantwort
    {
        $art = $this->art($dialog);

        if (! $art instanceof AppointmentType) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keineSlots());
        }

        $jetzt = CarbonImmutable::now();
        $tage = (int) config('mrs.agent.proposal_days', 14);

        $vorschlaege = $this->verfuegbarkeit->freieStartzeiten(
            art: $art,
            von: $jetzt,
            bis: $jetzt->addDays($tage),
            nurBehandler: $this->wunsch($dialog),
            nurStandort: $dialog->location_id === null
                ? null
                : Location::query()->whereKey($dialog->location_id)->first(),
            jetzt: $jetzt,
        );

        $vorschlaege = array_slice($vorschlaege, 0, (int) config('mrs.agent.proposal_count', 3));

        if ($vorschlaege === []) {
            // **Keine Sackgasse** (Testfall 14): die Warteliste statt einer
            // Uebergabe. Wer sie ablehnt, bekommt dann einen Menschen.
            return $this->frage($dialog, BookingState::WartelisteAnbieten, $this->texte->wartelisteAnbieten($art));
        }

        $dialog->setzeAngebote(array_map(
            fn (Slotvorschlag $vorschlag): string => $vorschlag->startsAt->toIso8601String(),
            $vorschlaege,
        ));
        $dialog->save();

        return $this->frage($dialog, BookingState::SlotsVorschlagen, $this->texte->slotsVorschlagen($vorschlaege));
    }

    private function daten(AgentDialog $dialog, Conversation $gespraech, Message $nachricht, bool $frisch = false): Dialogantwort
    {
        $name = $this->name($dialog, $gespraech, $nachricht, $frisch);

        if ($name === null) {
            return $this->frage($dialog, BookingState::DatenErheben, $this->texte->nameFragen());
        }

        $dialog->name = $name;
        $dialog->save();
        $dialog->wechsleNach(BookingState::Einwilligung);

        return Dialogantwort::sagt($this->texte->einwilligungFragen());
    }

    private function einwilligung(AgentDialog $dialog, Conversation $gespraech, Message $nachricht): Dialogantwort
    {
        if ($this->deutung->ablehnung($nachricht)) {
            // Ohne Einwilligung kein Termin -- und kein Hold, der einen
            // echten blockiert.
            $this->gibHoldFrei($dialog);

            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence);
        }

        if (! $this->deutung->zustimmung($nachricht)) {
            return $this->frage($dialog, BookingState::Einwilligung, $this->texte->einwilligungFragen());
        }

        $this->einwilligungen->erteile(
            $gespraech->channelIdentity,
            ConsentType::ServiceMessages,
            'agent-dialog',
            $this->texte->einwilligungFragen(),
        );

        $dialog->consent_at = CarbonImmutable::now();
        $dialog->save();
        $dialog->wechsleNach(BookingState::Bestaetigen);

        $vorschlag = $this->vorschlagAusHold($dialog);

        if (! $vorschlag instanceof Slotvorschlag) {
            return $this->vorschlagen($dialog);
        }

        return Dialogantwort::sagt($this->texte->bestaetigungFragen($vorschlag, (string) $dialog->name));
    }

    private function bestaetigen(AgentDialog $dialog, Conversation $gespraech, Message $nachricht): Dialogantwort
    {
        if ($this->deutung->ablehnung($nachricht)) {
            $this->gibHoldFrei($dialog);
            $dialog->wechsleNach(BookingState::SlotsVorschlagen);

            return $this->vorschlagen($dialog);
        }

        if (! $this->deutung->zustimmung($nachricht)) {
            $vorschlag = $this->vorschlagAusHold($dialog);

            if (! $vorschlag instanceof Slotvorschlag) {
                return $this->vorschlagen($dialog);
            }

            return $this->frage(
                $dialog,
                BookingState::Bestaetigen,
                $this->texte->bestaetigungFragen($vorschlag, (string) $dialog->name),
            );
        }

        return $this->buche($dialog, $gespraech);
    }

    /**
     * Die Antwort auf das Wartelistenangebot (Testfall 14).
     *
     * **Das Ja ist zugleich die Einwilligung in den Kanal** (K11) -- der Satz,
     * dem zugestimmt wurde, sagt es ausdruecklich und wird im Wortlaut
     * festgehalten. `consent_at` am Vorgang merkt sich, dass es schon kam,
     * falls danach noch der Name fehlt.
     */
    private function warteliste(AgentDialog $dialog, Conversation $gespraech, Message $nachricht): Dialogantwort
    {
        $art = $this->art($dialog);

        if (! $art instanceof AppointmentType) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keineSlots());
        }

        if ($dialog->consent_at === null) {
            if ($this->deutung->ablehnung($nachricht)) {
                return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keineSlots());
            }

            if (! $this->deutung->zustimmung($nachricht)) {
                return $this->frage($dialog, BookingState::WartelisteAnbieten, $this->texte->wartelisteAnbieten($art));
            }

            $this->einwilligungen->erteile(
                $gespraech->channelIdentity,
                ConsentType::ServiceMessages,
                'agent-warteliste',
                $this->texte->wartelisteAnbieten($art),
            );

            $dialog->consent_at = CarbonImmutable::now();
            $dialog->save();
        }

        // Die Zustimmung selbst ist kein Name; erst die naechste Nachricht
        // darf einer sein.
        $name = $this->name($dialog, $gespraech, $nachricht, frisch: $dialog->wasChanged('consent_at'));

        if ($name === null) {
            return $this->frage($dialog, BookingState::WartelisteAnbieten, $this->texte->nameFuerWarteliste());
        }

        $dialog->name = $name;
        $dialog->save();

        $this->trageEin($dialog, $art, $this->kontakt($dialog, $gespraech));

        $dialog->wechsleNach(BookingState::AufWarteliste);

        return Dialogantwort::sagt($this->texte->aufWarteliste($art));
    }

    /**
     * Legt den Wartelisteneintrag an -- mit dem, was gesagt wurde, und sonst
     * ohne Einschraenkung (config mrs.agent.waitlist).
     *
     * **Kein zweiter Eintrag** fuer dieselbe Person und Terminart: wer zweimal
     * fragt, soll nicht zweimal Angebote bekommen.
     */
    private function trageEin(AgentDialog $dialog, AppointmentType $art, Contact $kontakt): void
    {
        $vorhanden = WaitlistEntry::query()
            ->wartend()
            ->where('contact_id', $kontakt->getKey())
            ->where('appointment_type_id', $art->getKey())
            ->exists();

        if ($vorhanden) {
            return;
        }

        $heute = CarbonImmutable::now();
        $tage = (int) config('mrs.agent.waitlist.days', 60);

        $eintrag = new WaitlistEntry;
        $eintrag->contact_id = $kontakt->getKey();
        $eintrag->appointment_type_id = $art->getKey();
        $eintrag->practitioner_id = $dialog->requested_practitioner_id;
        $eintrag->status = WaitlistStatus::Active;
        $eintrag->all_locations = $dialog->location_id === null;
        $eintrag->earliest_date = $heute->startOfDay();
        $eintrag->latest_date = $heute->addDays($tage)->startOfDay();
        $eintrag->weekday_mask = 127;
        $eintrag->time_windows = null;
        $eintrag->min_notice_hours = (int) config('mrs.agent.waitlist.min_notice_hours', 24);
        $eintrag->priority = 0;
        $eintrag->expires_at = $heute->addDays($tage);
        $eintrag->save();

        if ($dialog->location_id !== null) {
            $eintrag->locations()->sync([$dialog->location_id]);
        }
    }

    /* Ein Termin, der schon steht (offen seit WP-24) ------------------------- */

    private function willAendern(Klassifikation $einordnung): bool
    {
        return $einordnung->absicht === AgentIntent::CancelRequest
            || $einordnung->absicht === AgentIntent::RescheduleRequest;
    }

    /**
     * Welcher Termin gemeint ist -- **eindeutig oder gar nicht**.
     *
     * Die Person muss bekannt sein, und sie darf genau einen anstehenden
     * Termin haben. Sonst uebernimmt ein Mensch: eine Absage am falschen Tag
     * ist schlimmer als eine, die fuenf Minuten spaeter kommt (Regel 6).
     */
    private function aenderungBeginnen(AgentDialog $dialog, Conversation $gespraech, AgentIntent $absicht): Dialogantwort
    {
        $this->gibHoldFrei($dialog);

        $kontakt = $gespraech->contact;

        if (! $kontakt instanceof Contact) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->wenMeinenSie());
        }

        $termine = Appointment::query()
            ->where('contact_id', $kontakt->getKey())
            ->where('starts_at', '>', CarbonImmutable::now())
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
            ->with(['appointmentType', 'practitioner', 'location'])
            ->orderBy('starts_at')
            ->get();

        if ($termine->isEmpty()) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keinTerminGefunden());
        }

        if ($termine->count() > 1) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->mehrereTermine());
        }

        $termin = $termine->firstOrFail();

        $dialog->change_appointment_id = $termin->getKey();
        $dialog->setzeAngebote([]);
        $dialog->save();

        if ($absicht === AgentIntent::CancelRequest) {
            return $this->frage($dialog, BookingState::AbsageBestaetigen, $this->texte->absageFragen(Slotvorschlag::ausTermin($termin)));
        }

        $dialog->wechsleNach(BookingState::VerschiebenVorschlagen);

        return $this->verschiebenVorschlagen($dialog, $termin);
    }

    private function absageBestaetigen(AgentDialog $dialog, Message $nachricht): Dialogantwort
    {
        $termin = $this->zuAendern($dialog);

        if (! $termin instanceof Appointment) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keinTerminGefunden());
        }

        $slot = Slotvorschlag::ausTermin($termin);

        if ($this->deutung->ablehnung($nachricht)) {
            $dialog->wechsleNach(BookingState::Geaendert);

            return Dialogantwort::sagt($this->texte->bleibtBestehen($slot));
        }

        if (! $this->deutung->zustimmung($nachricht)) {
            return $this->frage($dialog, BookingState::AbsageBestaetigen, $this->texte->absageFragen($slot));
        }

        // **Die Person sagt ab, nicht die Praxis** -- und die Zeit geht sofort
        // an die Warteliste (Terminplaner::sageAb, WP-25).
        $this->planer->sageAb($termin, CancellationReason::Contact);

        $dialog->wechsleNach(BookingState::Geaendert);

        return Dialogantwort::sagt($this->texte->abgesagt($slot));
    }

    /**
     * Freie Zeiten fuer denselben Termin: dieselbe Terminart, derselbe
     * Standort, dieselbe Behandlerin -- es sei denn, es wurde eine andere
     * gewuenscht.
     */
    private function verschiebenVorschlagen(AgentDialog $dialog, Appointment $termin): Dialogantwort
    {
        $jetzt = CarbonImmutable::now();

        $vorschlaege = array_values(array_filter(
            $this->verfuegbarkeit->freieStartzeiten(
                art: $termin->appointmentType,
                von: $jetzt,
                bis: $jetzt->addDays((int) config('mrs.agent.proposal_days', 14)),
                nurBehandler: $this->wunsch($dialog) ?? $termin->practitioner,
                nurStandort: $termin->location,
                jetzt: $jetzt,
            ),
            fn (Slotvorschlag $vorschlag): bool => ! $vorschlag->startsAt->equalTo($termin->starts_at),
        ));

        $vorschlaege = array_slice($vorschlaege, 0, (int) config('mrs.agent.proposal_count', 3));

        if ($vorschlaege === []) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keineAlternative());
        }

        $dialog->setzeAngebote(array_map(
            fn (Slotvorschlag $vorschlag): string => $vorschlag->startsAt->toIso8601String(),
            $vorschlaege,
        ));
        $dialog->save();

        return $this->frage(
            $dialog,
            BookingState::VerschiebenVorschlagen,
            $this->texte->verschiebenVorschlagen(Slotvorschlag::ausTermin($termin), $vorschlaege),
        );
    }

    private function verschiebenWahl(AgentDialog $dialog, Message $nachricht): Dialogantwort
    {
        $termin = $this->zuAendern($dialog);

        if (! $termin instanceof Appointment) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keinTerminGefunden());
        }

        $gewaehlt = $this->deutung->wahl($nachricht, $dialog->angebote());
        $ziel = $gewaehlt === null ? null : $this->zielFuer($dialog, $termin, CarbonImmutable::parse($gewaehlt));

        if (! $ziel instanceof Slotvorschlag) {
            return $this->verschiebenVorschlagen($dialog, $termin);
        }

        // Gehalten, bis bestaetigt ist -- wie beim Buchen.
        $hold = $this->halter->halte($ziel, HoldPurpose::AgentDialog);

        $dialog->slot_hold_id = $hold->getKey();
        $dialog->save();
        $dialog->wechsleNach(BookingState::VerschiebenBestaetigen);

        return Dialogantwort::sagt($this->texte->verschiebenFragen(Slotvorschlag::ausTermin($termin), $ziel));
    }

    private function verschiebenBestaetigen(AgentDialog $dialog, Message $nachricht): Dialogantwort
    {
        $termin = $this->zuAendern($dialog);
        $hold = $dialog->hold;
        $ziel = $this->vorschlagAusHold($dialog);

        if (! $termin instanceof Appointment) {
            $this->gibHoldFrei($dialog);

            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->keinTerminGefunden());
        }

        if (! $hold instanceof SlotHold || ! $ziel instanceof Slotvorschlag || ! $hold->giltNoch()) {
            $this->gibHoldFrei($dialog);
            $dialog->wechsleNach(BookingState::VerschiebenVorschlagen);

            return $this->verschiebenVorschlagen($dialog, $termin);
        }

        if ($this->deutung->ablehnung($nachricht)) {
            $this->gibHoldFrei($dialog);
            $dialog->wechsleNach(BookingState::Geaendert);

            return Dialogantwort::sagt($this->texte->bleibtBestehen(Slotvorschlag::ausTermin($termin)));
        }

        if (! $this->deutung->zustimmung($nachricht)) {
            return $this->frage(
                $dialog,
                BookingState::VerschiebenBestaetigen,
                $this->texte->verschiebenFragen(Slotvorschlag::ausTermin($termin), $ziel),
            );
        }

        // **Dieselbe Zeile, neue Zeit** (Terminplaner::verschiebe). Der Hold
        // wird in derselben Transaktion frei, in der die Zeilen belegt werden
        // -- dazwischen kommt niemand an sie heran.
        try {
            DB::transaction(function () use ($dialog, $termin, $ziel): void {
                $this->gibHoldFrei($dialog);
                $this->planer->verschiebe($termin, $ziel);
            });
        } catch (SlotNichtVerfuegbar|TerminNichtAenderbar) {
            $dialog->wechsleNach(BookingState::VerschiebenVorschlagen);

            return $this->verschiebenVorschlagen($dialog, $termin->refresh());
        }

        $dialog->wechsleNach(BookingState::Geaendert);

        return Dialogantwort::sagt($this->texte->verschoben($ziel));
    }

    /**
     * Der Termin, um den es geht -- und nur, solange er noch zu aendern ist
     * und der Person gehoert, die schreibt.
     */
    private function zuAendern(AgentDialog $dialog): ?Appointment
    {
        if ($dialog->change_appointment_id === null) {
            return null;
        }

        $termin = Appointment::query()
            ->whereKey($dialog->change_appointment_id)
            ->with(['appointmentType', 'practitioner', 'location'])
            ->first();

        $kontakt = $dialog->conversation?->contact;

        if (! $termin instanceof Appointment
            || ! $termin->istAenderbar()
            || ! $kontakt instanceof Contact
            || $termin->contact_id !== $kontakt->getKey()) {
            return null;
        }

        return $termin;
    }

    private function zielFuer(AgentDialog $dialog, Appointment $termin, CarbonImmutable $start): ?Slotvorschlag
    {
        foreach ($this->verfuegbarkeit->freieStartzeiten(
            art: $termin->appointmentType,
            von: $start->startOfDay(),
            bis: $start->endOfDay(),
            nurBehandler: $this->wunsch($dialog) ?? $termin->practitioner,
            nurStandort: $termin->location,
        ) as $vorschlag) {
            if ($vorschlag->startsAt->equalTo($start)) {
                return $vorschlag;
            }
        }

        return null;
    }

    /** Steht der Termin, den dieser Vorgang gebucht hat, noch? */
    private function terminDesVorgangsSteht(AgentDialog $dialog): bool
    {
        if ($dialog->appointment_id === null) {
            return false;
        }

        $termin = Appointment::query()->whereKey($dialog->appointment_id)->first();

        return $termin instanceof Appointment && $termin->istAenderbar() && $termin->ends_at->isFuture();
    }

    /** Ein neuer Vorgang an derselben Zeile -- G9 gilt je Konversation. */
    private function neuerVorgang(AgentDialog $dialog): void
    {
        $this->gibHoldFrei($dialog);

        $dialog->treatment_id = null;
        $dialog->appointment_type_id = null;
        $dialog->location_id = null;
        $dialog->practitioner_id = null;
        $dialog->requested_practitioner_id = null;
        $dialog->appointment_id = null;
        $dialog->change_appointment_id = null;
        $dialog->consent_at = null;
        $dialog->setzeAngebote([]);
        $dialog->save();
        $dialog->wechsleNach(BookingState::Start);
    }

    private function buche(AgentDialog $dialog, Conversation $gespraech): Dialogantwort
    {
        $hold = $dialog->hold;
        $vorschlag = $this->vorschlagAusHold($dialog);

        if (! $hold instanceof SlotHold || ! $vorschlag instanceof Slotvorschlag || ! $hold->giltNoch()) {
            return $this->vorschlagen($dialog);
        }

        $kontakt = $this->kontakt($dialog, $gespraech);

        $termin = $this->planer->loeseEin(
            hold: $hold,
            vorschlag: $vorschlag,
            kontakt: $kontakt,
            kanal: BookingChannel::Agent,
            status: AppointmentStatus::Pending,
            einwilligung: $dialog->consent_at,
        );

        $dialog->appointment_id = $termin->getKey();
        $dialog->slot_hold_id = null;
        $dialog->save();
        $dialog->wechsleNach(BookingState::Gebucht);

        return Dialogantwort::sagt($this->texte->gebucht($vorschlag), gebucht: true);
    }

    /* Hilfen --------------------------------------------------------------- */

    /**
     * Fragt nach -- und uebergibt beim vierten Mal.
     *
     * **Ein Agent, der viermal dasselbe fragt, ist schlimmer als kein Agent**
     * (Schritt 6). Gezaehlt wird je Zustand; ein Wechsel setzt zurueck.
     */
    private function frage(AgentDialog $dialog, BookingState $zustand, string $text): Dialogantwort
    {
        if ($dialog->state !== $zustand) {
            $dialog->wechsleNach($zustand);
        }

        $dialog->attempts++;
        $dialog->save();

        if ($dialog->attempts > (int) config('mrs.agent.max_clarifications_per_state', 3)) {
            $this->gibHoldFrei($dialog);

            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence);
        }

        return Dialogantwort::sagt($text);
    }

    /**
     * Uebernimmt, was die Einordnung erkannt hat.
     *
     * **Eine Meinungsaenderung setzt zurueck** (Schritt 6): wer mitten im
     * Ablauf eine andere Behandlung nennt, bekommt neue Vorschlaege -- und
     * der gehaltene Slot wird frei, statt bis zum Ablauf zu blockieren.
     */
    private function uebernimm(AgentDialog $dialog, Klassifikation $einordnung): void
    {
        if ($einordnung->treatmentId !== null) {
            $behandlung = Treatment::query()->whereUuid($einordnung->treatmentId)->first();

            if ($behandlung instanceof Treatment && $behandlung->getKey() !== $dialog->treatment_id) {
                $gewechselt = $dialog->treatment_id !== null;

                $this->setzeBehandlung($dialog, $behandlung);

                if ($gewechselt) {
                    $this->gibHoldFrei($dialog);
                    $dialog->setzeAngebote([]);
                    $dialog->save();
                    $dialog->wechsleNach(BookingState::SlotsVorschlagen);
                }
            }
        }

        // **Ein neuer Behandlerwunsch setzt die Vorschlaege zurueck** -- wer
        // nach drei Terminen fragt "geht das auch bei Frau Dr. Sauer?", will
        // ihre sehen, nicht die alten.
        if ($einordnung->practitionerId !== null) {
            $behandler = Practitioner::query()->whereUuid($einordnung->practitionerId)->first();

            if ($behandler instanceof Practitioner && $behandler->getKey() !== $dialog->requested_practitioner_id) {
                $dialog->requested_practitioner_id = $behandler->getKey();
                $dialog->save();

                if ($dialog->state->haeltSlot()) {
                    $this->gibHoldFrei($dialog);
                    $dialog->setzeAngebote([]);
                    $dialog->save();
                    $dialog->wechsleNach(BookingState::SlotsVorschlagen);
                }
            }
        }

        if ($einordnung->locationId !== null) {
            $standort = Location::query()->whereUuid($einordnung->locationId)->first();

            if ($standort instanceof Location) {
                $dialog->location_id = $standort->getKey();
                $dialog->save();
            }
        }
    }

    private function setzeBehandlung(AgentDialog $dialog, Treatment $behandlung): void
    {
        $art = AppointmentType::query()
            ->aktiv()
            ->where('treatment_id', $behandlung->getKey())
            ->orderBy('duration_minutes')
            ->first();

        $dialog->treatment_id = $behandlung->getKey();
        $dialog->appointment_type_id = $art?->getKey();
        $dialog->save();
    }

    /** Ein abgelaufener Hold ist kein Fehler (Testfall 16). */
    private function pruefeHold(AgentDialog $dialog): void
    {
        $hold = $dialog->hold;

        if ($hold instanceof SlotHold && ! $hold->giltNoch()) {
            $dialog->slot_hold_id = null;
            $dialog->setzeAngebote([]);
            $dialog->save();

            if ($dialog->state->haeltSlot()) {
                $dialog->wechsleNach(BookingState::SlotsVorschlagen);
            }
        }
    }

    private function gibHoldFrei(AgentDialog $dialog): void
    {
        $hold = $dialog->hold;

        if ($hold instanceof SlotHold && $hold->giltNoch()) {
            $this->halter->gibFrei($hold);
        }

        if ($dialog->slot_hold_id !== null) {
            $dialog->slot_hold_id = null;
            $dialog->save();
        }
    }

    private function rueckfrageZumBestehenden(AgentDialog $dialog): Dialogantwort
    {
        $termin = $dialog->appointment_id === null
            ? null
            : Appointment::query()->whereKey($dialog->appointment_id)->first();

        if (! $termin instanceof Appointment) {
            return Dialogantwort::uebergibt(GuardrailHit::LowConfidence);
        }

        return Dialogantwort::uebergibt(GuardrailHit::LowConfidence, $this->texte->bereitsGebucht(Slotvorschlag::ausTermin($termin)));
    }

    private function name(AgentDialog $dialog, Conversation $gespraech, Message $nachricht, bool $frisch): ?string
    {
        if (is_string($dialog->name) && $dialog->name !== '') {
            return $dialog->name;
        }

        $kontakt = $gespraech->contact;

        if ($kontakt instanceof Contact) {
            return $kontakt->name();
        }

        $anzeigename = $gespraech->channelIdentity->display_name;

        if (is_string($anzeigename) && trim($anzeigename) !== '') {
            return trim($anzeigename);
        }

        if ($frisch) {
            // Die Nachricht war die Terminwahl, nicht der Name.
            return null;
        }

        $text = trim((string) $nachricht->body);

        return $text !== '' && mb_strlen($text) <= 80 ? $text : null;
    }

    private function kontakt(AgentDialog $dialog, Conversation $gespraech): Contact
    {
        $kontakt = $gespraech->contact;

        if ($kontakt instanceof Contact) {
            return $kontakt;
        }

        $teile = preg_split('/\s+/', trim((string) $dialog->name)) ?: [];
        $nachname = count($teile) > 1 ? (string) array_pop($teile) : (string) ($teile[0] ?? 'Unbekannt');

        $kontakt = $this->kontakte->findeOderLege([
            'first_name' => count($teile) > 0 ? implode(' ', $teile) : '',
            'last_name' => $nachname,
        ]);

        // Die Zuordnung gehoert an die Kennung, nicht nur an das Gespraech
        // (Entscheidung D5): beim naechsten Mal weiss das Produkt schon, wer
        // schreibt.
        $identitaet = $gespraech->channelIdentity;
        $identitaet->contact_id = $kontakt->getKey();
        $identitaet->save();

        $gespraech->contact_id = $kontakt->getKey();
        $gespraech->save();

        return $kontakt;
    }

    /** Der geaeusserte Behandlerwunsch -- oder keiner. */
    private function wunsch(AgentDialog $dialog): ?Practitioner
    {
        return $dialog->requested_practitioner_id === null
            ? null
            : Practitioner::query()->whereKey($dialog->requested_practitioner_id)->where('is_active', true)->first();
    }

    /**
     * Wer fuer die Frage "bei wem?" in Betracht kommt: wer diese Terminart
     * anbietet, sonst alle.
     *
     * @return list<Practitioner>
     */
    private function behandlerFuer(AgentDialog $dialog): array
    {
        $art = $this->art($dialog);

        $abfrage = $art instanceof AppointmentType
            ? $art->practitioners()->where('practitioners.is_active', true)
            : Practitioner::query()->where('is_active', true);

        return array_values($abfrage->orderBy('last_name')->get()->all());
    }

    private function art(AgentDialog $dialog): ?AppointmentType
    {
        return $dialog->appointment_type_id === null
            ? null
            : AppointmentType::query()->whereKey($dialog->appointment_type_id)->first();
    }

    private function vorschlagZu(AgentDialog $dialog, CarbonImmutable $start): ?Slotvorschlag
    {
        $art = $this->art($dialog);

        if (! $art instanceof AppointmentType) {
            return null;
        }

        $jetzt = CarbonImmutable::now();

        foreach ($this->verfuegbarkeit->freieStartzeiten(
            art: $art,
            von: $start->startOfDay(),
            bis: $start->endOfDay(),
            // Dieselbe Einschraenkung wie beim Vorschlagen: um 10 Uhr koennen
            // zwei frei sein, und gemeint ist die, nach der gefragt wurde.
            nurBehandler: $this->wunsch($dialog),
            nurStandort: $dialog->location_id === null
                ? null
                : Location::query()->whereKey($dialog->location_id)->first(),
            jetzt: $jetzt,
        ) as $vorschlag) {
            if ($vorschlag->startsAt->equalTo($start)) {
                return $vorschlag;
            }
        }

        return null;
    }

    private function vorschlagAusHold(AgentDialog $dialog): ?Slotvorschlag
    {
        $hold = $dialog->hold;

        if (! $hold instanceof SlotHold) {
            return null;
        }

        $art = AppointmentType::query()->whereKey($hold->appointment_type_id)->first();
        $behandler = Practitioner::query()->whereKey($hold->practitioner_id)->first();
        $standort = Location::query()->whereKey($hold->location_id)->first();

        if (! $art instanceof AppointmentType
            || ! $behandler instanceof Practitioner
            || ! $standort instanceof Location) {
            return null;
        }

        return Slotvorschlag::ab($art, $behandler, $standort, $hold->blocked_from->addMinutes($art->buffer_before_minutes));
    }

    private function vorgang(Conversation $gespraech): AgentDialog
    {
        $dialog = AgentDialog::query()->where('conversation_id', $gespraech->getKey())->first();

        if ($dialog instanceof AgentDialog) {
            return $dialog;
        }

        $neu = new AgentDialog;
        $neu->conversation_id = $gespraech->getKey();
        $neu->state = BookingState::Start;
        $neu->save();

        return $neu;
    }
}
