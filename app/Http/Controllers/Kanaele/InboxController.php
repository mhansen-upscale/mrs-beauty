<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kanaele;

use App\Enums\Ability;
use App\Enums\AgentAction;
use App\Enums\AgentMode;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\GuardrailHit;
use App\Enums\LeadStatus;
use App\Enums\MessageDirection;
use App\Enums\TemplateStatus;
use App\Http\Controllers\Controller;
use App\Kanaele\Konversationen;
use App\Kanaele\Nachrichtenversand;
use App\Kanaele\Posteingang;
use App\Kontakte\Kontaktsuche;
use App\Kontakte\Notizbuch;
use App\Leads\Leadverwaltung;
use App\Models\AgentRun;
use App\Models\Attachment;
use App\Models\ChannelConnection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Note;
use App\Models\Tag;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der Posteingang: sehen, lesen, antworten.
 *
 * **Regel 5 wird hier sichtbar.** Bis WP-20 war eine Nachricht eine
 * Zeichenkette in einer Spalte; ab hier wird sie angezeigt. Angezeigt wird
 * sie als **Text**, nie als Auszeichnung -- die Oberflaeche kennt kein
 * `v-html`, und ein Test haelt das fest.
 *
 * **Das Service-Fenster ist eine Kostenanzeige** (Entscheidungen B7, B8).
 * Wer erst beim Absenden erfaehrt, dass eine Antwort jetzt ein
 * kostenpflichtiges Template braucht, hat das Geld schon ausgegeben.
 */
final class InboxController extends Controller
{
    public function __construct(
        private readonly Posteingang $posteingang,
        private readonly Konversationen $konversationen,
        private readonly Nachrichtenversand $versand,
        private readonly Kontaktsuche $kontakte,
        private readonly Leadverwaltung $leads,
        private readonly Notizbuch $notizbuch,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Ability::ViewInbox->value);

        $kanal = ChannelType::tryFrom((string) $request->query('kanal', ''));
        $zustand = ConversationStatus::tryFrom((string) $request->query('zustand', 'open'));
        $ungelesen = $request->boolean('ungelesen');
        $suche = trim((string) $request->query('suche', ''));

        $gespraeche = $this->posteingang->liste($kanal, $zustand, $ungelesen, $suche);

        // **Ausgewaehlt oder nur aufgeschlagen?** Auf einem breiten Bildschirm
        // steht das erste Gespraech von selbst offen; auf einem Telefon waere
        // dasselbe eine Falle -- der Zurueck-Knopf fuehrte wieder hierher, und
        // die Liste bekaeme niemand zu sehen.
        $ausgewaehlt = trim((string) $request->query('gespraech', '')) !== '';
        $offen = $this->gewaehltes($request, $gespraeche->first());

        return Inertia::render('inbox/Index', [
            'conversations' => $gespraeche->map($this->kopfzeile(...))->values(),
            'unread' => $this->posteingang->ungelesen(),

            'filter' => [
                'kanal' => $kanal?->value,
                'zustand' => $zustand?->value,
                'ungelesen' => $ungelesen,
                'suche' => $suche,
            ],

            // **Nur die beiden bedienten Kanaele** (Entscheidung P11): ein
            // Filter auf etwas, das nie ankommt, ist eine Sackgasse.
            'channels' => collect([ChannelType::WhatsApp, ChannelType::Email])
                ->map(fn (ChannelType $kanal): array => ['value' => $kanal->value, 'label' => $kanal->label()])
                ->values(),

            // Der Hinweis gehoert sichtbar in die Oberflaeche (P8).
            'searchField' => $suche === '' ? null : Kontaktsuche::feldFuer($suche),

            'conversation' => $offen instanceof Conversation ? $this->verlauf($offen) : null,
            'ausgewaehlt' => $ausgewaehlt && $offen instanceof Conversation,

            'connections' => ChannelConnection::query()
                ->get()
                ->map(fn (ChannelConnection $verbindung): array => [
                    'channel' => $verbindung->channel->value,
                    'label' => $verbindung->channel->label(),
                    'status' => $verbindung->status->value,
                    'statusLabel' => $verbindung->status->label(),
                    'stoerung' => $verbindung->status->brauchtAufmerksamkeit(),
                ])
                ->values(),

            // Die Suche im Zuordnen-Dialog: **exakt**, wie ueberall
            // (Entscheidung P8).
            'kontaktsuche' => $this->kontaktsuche($request),

            'darfAntworten' => Gate::allows(Ability::ReplyInbox->value),
            'darfZuordnen' => Gate::allows(Ability::ManageContacts->value),
        ]);
    }

    /**
     * Antwortet im Gespraech.
     *
     * **Eingereiht, nicht gesendet** (Regel 4): der Versand laeuft ueber die
     * Queue, und die Oberflaeche bleibt bedienbar, wenn der Kanal ausfaellt.
     */
    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize(Ability::ReplyInbox->value);

        $daten = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $this->versand->stelleEin($conversation, $daten['body']);

        // Wer antwortet, hat reagiert (WP-17): Speed-to-Lead misst ab hier.
        $this->leads->beiAntwort($conversation);

        return back();
    }

    /** Antwortet mit einem genehmigten Template -- der Weg aus dem geschlossenen Fenster. */
    public function template(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize(Ability::ReplyInbox->value);

        $daten = $request->validate([
            'template' => ['required', 'string'],
            'werte' => ['array'],
            'werte.*' => ['string', 'max:500'],
        ]);

        $template = WhatsAppTemplate::query()->whereUuid($daten['template'])->firstOrFail();

        if ($template->status !== TemplateStatus::Approved) {
            return back()->withErrors(['template' => 'Dieses Template ist nicht genehmigt.']);
        }

        /** @var list<string> $werte */
        $werte = array_values(array_map(strval(...), $daten['werte'] ?? []));

        $this->versand->stelleTemplateEin($conversation, $template, $werte);

        $this->leads->beiAntwort($conversation);

        return back();
    }

    /**
     * Stellt den Modus des Agenten um -- **sofort wirksam** (Entscheidung G8).
     *
     * `auto` ist seit WP-24 waehlbar: der Agent antwortet dann selbst, und
     * zwar mit Kennzeichnung (G6). Alles aus WP-23 gilt davor -- harte
     * Weiche, Konfidenz, Not-Aus, Kontingent, Nachpruefung.
     */
    public function agentModus(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize(Ability::ManageAgent->value);

        $daten = $request->validate([
            'modus' => ['required', 'in:off,suggest,auto'],
        ]);

        $conversation->agent_mode = AgentMode::from($daten['modus']);
        $conversation->save();

        return back();
    }

    /**
     * Laesst den Assistenten wieder in das Gespraech.
     *
     * Nach einer Komplikation, einer Beschwerde oder einem Bild haelt er sich
     * heraus (WP-23). Aufheben kann das ein Mensch -- ausdruecklich, nicht
     * durch Zeitablauf allein.
     */
    public function agentFortsetzen(Conversation $conversation): RedirectResponse
    {
        Gate::authorize(Ability::ManageAgent->value);

        $conversation->agent_paused_until = null;
        $conversation->save();

        return back();
    }

    public function close(Conversation $conversation): RedirectResponse
    {
        Gate::authorize(Ability::ReplyInbox->value);

        $this->konversationen->schliesse($conversation);

        return back();
    }

    public function reopen(Conversation $conversation): RedirectResponse
    {
        Gate::authorize(Ability::ReplyInbox->value);

        $conversation->status = ConversationStatus::Open;
        $conversation->closed_at = null;
        $conversation->save();

        return back();
    }

    /**
     * Ordnet das Gespraech einer Person zu.
     *
     * **Am Kanal, nicht am Gespraech** (Entscheidung D5): die Zuordnung gilt
     * der Kennung -- dieselbe Rufnummer schreibt im Maerz und im Oktober, und
     * beim zweiten Mal weiss das Produkt schon, wer das ist.
     */
    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $daten = $request->validate([
            'contact' => ['required', 'string'],
        ]);

        $kontakt = Contact::query()->whereUuid($daten['contact'])->firstOrFail();

        $identitaet = $conversation->channelIdentity;
        $identitaet->contact_id = $kontakt->getKey();
        $identitaet->save();

        $conversation->contact_id = $kontakt->getKey();
        $conversation->save();

        return back();
    }

    /**
     * Treffer fuer den Zuordnen-Dialog.
     *
     * @return list<array<string, mixed>>
     */
    private function kontaktsuche(Request $request): array
    {
        $begriff = trim((string) $request->query('kontaktsuche', ''));

        if ($begriff === '') {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return $this->kontakte->suche($begriff)
            ->map(fn (Contact $kontakt): array => [
                'uuid' => $kontakt->uuid,
                'name' => $kontakt->name(),
            ])
            ->values()
            ->all();
    }

    /**
     * Welches Gespraech geoeffnet ist.
     *
     * **Gelesen wird nur, was jemand ausgewaehlt hat.** Das erste Gespraech
     * steht auf einem breiten Bildschirm von selbst offen -- daraus zu
     * schliessen, jemand habe es gelesen, waere eine Zahl, die von allein
     * kleiner wird. Auf einem Telefon sieht man ohnehin zuerst die Liste.
     */
    private function gewaehltes(Request $request, ?Conversation $vorgabe): ?Conversation
    {
        $gewaehlt = (string) $request->query('gespraech', '');

        if ($gewaehlt === '') {
            return $vorgabe;
        }

        $gespraech = Conversation::query()->whereUuid($gewaehlt)->first();

        if ($gespraech instanceof Conversation && $gespraech->ungelesen()) {
            $this->posteingang->markiereGelesen($gespraech);
        }

        return $gespraech;
    }

    /**
     * Die Zeile in der Liste.
     *
     * **Ohne Inhalt.** Eine Vorschau des letzten Satzes waere bequem und
     * stuende dann auf jedem Bildschirm im Empfang, den jeder Wartende sieht.
     *
     * @return array<string, mixed>
     */
    private function kopfzeile(Conversation $gespraech): array
    {
        return [
            'uuid' => $gespraech->uuid,
            'channel' => $gespraech->channel->value,
            'channelLabel' => $gespraech->channel->label(),
            // Der Name des Kontakts, sonst der Profilname des Kanals, sonst
            // die Kennung. Wer uns zum ersten Mal schreibt, ist noch niemand
            // im Produkt -- aber WhatsApp und die Mail nennen einen Namen.
            'name' => $gespraech->contact?->name()
                ?? $gespraech->channelIdentity->display_name
                ?? $gespraech->channelIdentity->kennungAnzeige(),
            'bekannt' => $gespraech->contact instanceof Contact,
            'status' => $gespraech->status->value,
            'ungelesen' => $gespraech->ungelesen(),
            'letzteAktivitaet' => ($gespraech->last_inbound_at ?? $gespraech->created_at)?->toIso8601String(),
            'fensterOffen' => $gespraech->fensterOffen(),
            'fensterRestminuten' => $gespraech->fensterRestminuten(),
        ];
    }

    /**
     * Der Verlauf eines Gespraechs.
     *
     * @return array<string, mixed>
     */
    private function verlauf(Conversation $gespraech): array
    {
        $gespraech->loadMissing(['channelIdentity', 'contact']);

        $nachrichten = $gespraech->messages()
            ->with('attachments')
            ->orderBy('created_at')
            // **Der Schluessel als zweite Ordnung**, nicht als Zierde: zwei
            // Nachrichten derselben Sekunde stehen sonst in der Reihenfolge,
            // die die Datenbank gerade liefert -- und das ist beim naechsten
            // Lauf eine andere. Der Primaerschluessel ist UUIDv7 und damit
            // selbst zeitlich sortiert (Entscheidung A4).
            ->orderBy('id')
            ->limit(200)
            ->get();

        $braucht = $gespraech->channel->hatServicefenster() && ! $gespraech->fensterOffen();

        return [
            'uuid' => $gespraech->uuid,
            'channel' => $gespraech->channel->value,
            'channelLabel' => $gespraech->channel->label(),
            'status' => $gespraech->status->value,
            'agentModus' => $gespraech->agent_mode->value,
            'kennung' => $gespraech->channelIdentity->kennungAnzeige(),
            'anzeigename' => $gespraech->channelIdentity->display_name,

            'kontakt' => $gespraech->contact instanceof Contact ? [
                'uuid' => $gespraech->contact->uuid,
                'name' => $gespraech->contact->name(),

                // Notizen und Schlagworte (offen seit WP-18) -- nur fuer die,
                // die Kontakte pflegen. Wer nur mitliest, bekommt sie nicht.
                ...(Gate::allows(Ability::ManageContacts->value) ? [
                    'notizen' => $this->notizbuch->notizen($gespraech->contact)
                        ->map(fn (Note $notiz): array => [
                            'uuid' => $notiz->uuid,
                            'text' => $notiz->body,
                            'von' => $notiz->author?->name,
                            'wann' => $notiz->created_at?->toIso8601String(),
                        ])
                        ->values(),
                    'schlagworte' => $this->notizbuch->schlagworte($gespraech->contact)
                        ->map(fn (Tag $schlagwort): array => ['uuid' => $schlagwort->uuid, 'name' => $schlagwort->name])
                        ->values(),
                ] : []),
            ] : null,

            'anfrage' => $this->offeneAnfrage($gespraech),

            // **Das harte Signal** (Entscheidung D6): dieselbe Rufnummer,
            // dieselbe Adresse. Was kein hartes Signal hat, wird nicht
            // vorgeschlagen -- zwei Personen zusammenzufuehren, die nicht
            // dieselbe sind, faellt niemandem auf.
            'vorschlag' => $this->vorschlag($gespraech),

            // **Die Kostenanzeige** (B7, B8): sichtbar, bevor jemand tippt.
            'fenster' => [
                'gilt' => $gespraech->channel->hatServicefenster(),
                'offen' => $gespraech->fensterOffen(),
                'restminuten' => $gespraech->fensterRestminuten(),
                'brauchtTemplate' => $braucht,
            ],

            'templates' => $braucht ? $this->templates() : [],

            // Der Agent (WP-22): was er erkannt hat und was er vorschlaegt.
            // **Gesendet hat er nichts** -- `auto` ist bis WP-24 gesperrt.
            'agent' => $this->agent($gespraech),

            'messages' => $nachrichten->map(fn (Message $nachricht): array => [
                'uuid' => $nachricht->uuid,
                'eingehend' => $nachricht->direction === MessageDirection::Inbound,
                'status' => $nachricht->status->value,
                'statusLabel' => $nachricht->status->label(),
                'betreff' => $nachricht->subject,

                // Als **Text**. Was hier steht, sind Daten -- die Oberflaeche
                // setzt es mit `{{ }}`, niemals mit v-html (Regel 5).
                'inhalt' => $nachricht->body,

                'medientyp' => $nachricht->media_type,
                'kosten' => $nachricht->cost_category?->label(),
                'fehler' => $nachricht->failure,
                // **Wann sie ankam, nicht wann wir sie angelegt haben.** Ein
                // erneut eingespieltes Rohereignis (WP-19) entsteht heute und
                // traegt trotzdem den Zeitpunkt von damals.
                'zeitpunkt' => ($nachricht->istEingehend()
                    ? $nachricht->delivered_at
                    : $nachricht->sent_at)?->toIso8601String()
                    ?? $nachricht->created_at?->toIso8601String(),

                // Nur Freigegebenes: wer eine Datei ausliefert, bevor sie
                // geprueft ist, reicht weiter, was jemand ungefragt geschickt
                // hat (WP-18).
                'anhaenge' => $nachricht->attachments
                    ->filter(fn (Attachment $anhang): bool => $anhang->istFreigegeben())
                    ->map(fn (Attachment $anhang): array => [
                        'uuid' => $anhang->uuid,
                        'name' => $anhang->original_name,
                        'groesse' => $anhang->size_bytes,
                        'bild' => $anhang->istBild(),
                    ])
                    ->values(),
            ])->values(),
        ];
    }

    /**
     * Ein Kontakt, der zu dieser Kennung passt -- oder keiner.
     *
     * @return array<string, mixed>|null
     */
    private function vorschlag(Conversation $gespraech): ?array
    {
        if ($gespraech->contact instanceof Contact) {
            return null;
        }

        $kennung = $gespraech->channelIdentity->external_id;
        $kanal = $gespraech->channel;

        $kontakt = $this->kontakte->findeUeberHartesSignal(
            email: $kanal === ChannelType::Email ? $kennung : null,
            telefon: $kanal->istRufnummer() ? $kennung : null,
        );

        return $kontakt instanceof Contact
            ? ['uuid' => $kontakt->uuid, 'name' => $kontakt->name()]
            : null;
    }

    /**
     * Was der Agent zuletzt zu diesem Gespraech getan hat.
     *
     * @return array<string, mixed>
     */
    private function agent(Conversation $gespraech): array
    {
        $lauf = AgentRun::query()
            ->where('conversation_id', $gespraech->getKey())
            ->orderByDesc('created_at')
            ->first();

        return [
            'modus' => $gespraech->agent_mode->value,
            'modusLabel' => $gespraech->agent_mode->label(),

            'darfSchalten' => Gate::allows(Ability::ManageAgent->value),

            'absicht' => $lauf?->intent?->value,
            'absichtLabel' => $lauf?->intent?->label(),
            'sicherheit' => $lauf?->confidence,
            'aktion' => $lauf?->action->value,
            'aktionLabel' => $lauf?->action->label(),
            'grund' => $lauf?->escalation_reason,
            'fehler' => $lauf?->failure,

            // **Im Produkt einsehbar**, nicht nur im Log: einer Praxis muss
            // sich erklaeren lassen, warum ihr Assistent geschwiegen hat.
            'regeln' => array_values(array_map(
                fn (string $regel): string => GuardrailHit::tryFrom($regel)?->label() ?? $regel,
                $lauf instanceof AgentRun ? ($lauf->guardrails ?? []) : [],
            )),

            'pausiertBis' => $gespraech->agent_paused_until?->toIso8601String(),

            // Der Vorschlag geht **in das Eingabefeld**, nicht in den Kanal.
            'vorschlag' => $lauf?->action === AgentAction::Suggested ? $lauf->suggestion : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function offeneAnfrage(Conversation $gespraech): ?array
    {
        if (! $gespraech->contact instanceof Contact) {
            return null;
        }

        $anfrage = Lead::query()
            ->where('contact_id', $gespraech->contact->getKey())
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->orderByDesc('created_at')
            ->first();

        return $anfrage instanceof Lead ? [
            'uuid' => $anfrage->uuid,
            'status' => $anfrage->status->value,
            'statusLabel' => $anfrage->status->label(),
        ] : null;
    }

    /**
     * Genehmigte Templates -- **mit Kategorie**, denn die bestimmt den Preis.
     *
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        /** @var list<array<string, mixed>> */
        return WhatsAppTemplate::query()
            ->sendbar()
            ->with('pruefung')
            ->orderBy('name')
            ->get()
            ->map(fn (WhatsAppTemplate $template): array => [
                'uuid' => $template->uuid,
                'name' => $template->name,
                'sprache' => $template->language,
                'kategorie' => $template->category->label(),
                'kostet' => $template->category->kostetGeld(),
                'rumpf' => $template->body,
                'variablen' => $template->variables,

                // Die HWG-Ampel (WP-30) -- ein Hinweis, keine Sperre: das
                // Template hat Meta genehmigt, und Meta prueft kein HWG.
                'ampel' => $template->pruefung?->result->value,
                'befunde' => array_map(
                    fn (array $befund): string => (string) ($befund['titel'] ?? ''),
                    $template->pruefung?->befunde() ?? [],
                ),
            ])
            ->values()
            ->all();
    }
}
