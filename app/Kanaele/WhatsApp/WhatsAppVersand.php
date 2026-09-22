<?php

declare(strict_types=1);

namespace App\Kanaele\WhatsApp;

use App\Abrechnung\Kontingente;
use App\Datenschutz\Einwilligungen;
use App\Enums\ConsentType;
use App\Enums\TemplateStatus;
use App\Kanaele\Kanalfehler;
use App\Kanaele\Kanalversand;
use App\Kanaele\Versandergebnis;
use App\Models\ChannelConnection;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use App\Support\Fehlereinordnung;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Senden ueber die WhatsApp Cloud API.
 *
 * **Drei Pruefungen vor dem Aufruf**, alle drei ohne Wiederholung:
 *
 * 1. **Das Fenster.** 24 Stunden ab der letzten eingehenden Nachricht.
 *    Danach nimmt WhatsApp nur ein genehmigtes Template an. Eine Nachricht,
 *    die draussen als "gesendet" gilt und nie ankommt, faellt erst auf, wenn
 *    niemand antwortet.
 * 2. **Das Template.** Genehmigt wird je Sprache, nicht je Name.
 * 3. **Das Opt-in.** Wer uns schreibt, hat sich damit gemeldet -- die Antwort
 *    im Fenster braucht keinen weiteren Nachweis. Ein Template **ausserhalb**
 *    des Fensters ist eine Ansprache von unserer Seite und verlangt eine
 *    nachweisbare Einwilligung (docs/integrationen/meta.md, Abschnitt
 *    WhatsApp; Entscheidung D8).
 *
 * Die Antwort traegt **keine Kostenkategorie**. Sie kommt mit der
 * Statusrueckmeldung, gelesen von WhatsAppEingang.
 */
final class WhatsAppVersand implements Kanalversand
{
    public function __construct(
        private readonly Einwilligungen $einwilligungen,
        private readonly Kontingente $kontingente,
    ) {}

    public function sende(ChannelConnection $verbindung, Message $nachricht): Versandergebnis
    {
        $konversation = $nachricht->conversation;
        $template = $nachricht->template_id === null ? null : $nachricht->template;

        if ($template instanceof WhatsAppTemplate && $template->status !== TemplateStatus::Approved) {
            // Gar nicht erst aufrufen: WhatsApp lehnt es ab, und die Ablehnung
            // kostet eine Anfrage, die auf das Rate Limit geht.
            throw new Kanalfehler(new Fehlereinordnung('template_not_approved', wiederholen: false, zustand: null));
        }

        if (! $konversation->fensterOffen()) {
            if (! $template instanceof WhatsAppTemplate) {
                throw new Kanalfehler(new Fehlereinordnung('window_closed', wiederholen: false, zustand: null));
            }

            if (! $this->einwilligungen->darfSenden($konversation->channelIdentity, ConsentType::WhatsApp)) {
                throw new Kanalfehler(new Fehlereinordnung('no_optin', wiederholen: false, zustand: null));
            }

            // **Begrenzt wird, was Geld kostet** (Entscheidung B12, WP-06).
            // Ein Template ausserhalb des Fensters kostet -- eine Antwort im
            // Fenster nicht, und die wird nie gesperrt. Eine Praxis darf nie
            // daran gehindert werden, einer Patientin zu antworten.
            if (! $this->kontingente->darfKostenpflichtigSenden()) {
                throw new Kanalfehler(new Fehlereinordnung('quota_exhausted', wiederholen: false, zustand: null));
            }
        }

        $empfaenger = $konversation->channelIdentity->external_id;

        $antwort = $this->anfrage($verbindung)->post(
            $this->adresse($verbindung),
            $template instanceof WhatsAppTemplate
                ? $this->templatenutzlast($empfaenger, $template, $nachricht->templatewerte())
                : $this->textnutzlast($empfaenger, (string) $nachricht->body),
        );

        if ($antwort->failed()) {
            throw new Kanalfehler(Fehlereinordnung::ausMetaAntwort($antwort->status(), (array) $antwort->json()));
        }

        $kennung = data_get($antwort->json(), 'messages.0.id');

        if (! is_string($kennung) || $kennung === '') {
            // Ein 200 ohne Kennung ist keine Zustellung, die wir nachhalten
            // koennten -- und ohne Kennung kaeme auch keine Rueckmeldung an.
            throw new Kanalfehler(new Fehlereinordnung('no_message_id', wiederholen: false, zustand: null));
        }

        // **Ohne Kategorie.** Die Sendeantwort kennt die Kosten nicht; sie
        // steht in der Statusrueckmeldung. `none` waere hier die Schaetzung
        // "kostenlos".
        return new Versandergebnis($kennung);
    }

    /**
     * @return array<string, mixed>
     */
    private function textnutzlast(string $empfaenger, string $inhalt): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $empfaenger,
            'type' => 'text',

            // **Keine Vorschau.** Sie laedt die Zielseite und zeigt deren
            // Titel im Verlauf -- bei einem Buchungslink waere das der Name
            // der Praxis in einem fremden Cache.
            'text' => ['preview_url' => false, 'body' => $inhalt],
        ];
    }

    /**
     * Ein Template wird **mit Namen** geschickt, nicht mit Text.
     *
     * WhatsApp setzt die Parameter selbst ein. Wer den fertigen Text schickte,
     * bekaeme ihn ausserhalb des Fensters abgelehnt -- es waere dann keine
     * Templatenachricht mehr.
     *
     * @param  list<string>  $werte
     * @return array<string, mixed>
     */
    private function templatenutzlast(string $empfaenger, WhatsAppTemplate $template, array $werte): array
    {
        $nutzlast = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $empfaenger,
            'type' => 'template',
            'template' => [
                'name' => $template->name,
                'language' => ['code' => $template->language],
            ],
        ];

        if ($werte !== []) {
            $nutzlast['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $wert): array => ['type' => 'text', 'text' => $wert],
                    $werte,
                ),
            ]];
        }

        return $nutzlast;
    }

    /**
     * **Die Rufnummern-ID, nicht die WABA-Kennung.** Die Zustellung kommt
     * unter der einen an und der Versand laeuft ueber die andere.
     */
    private function adresse(ChannelConnection $verbindung): string
    {
        $basis = rtrim((string) config('mrs.meta.graph_url'), '/');
        $version = (string) config('mrs.meta.api_version');
        $absender = $verbindung->sender_id ?? $verbindung->external_id;

        return $basis.'/'.$version.'/'.$absender.'/messages';
    }

    private function anfrage(ChannelConnection $verbindung): PendingRequest
    {
        return Http::withToken((string) $verbindung->access_token)
            ->acceptJson()
            ->timeout(20)
            // Nur Ausfaelle werden hier wiederholt. Alles, was Meta als
            // Aussage zurueckgibt -- Rate Limit, Token, Berechtigung --,
            // ordnet Fehlereinordnung ein, und die Wiederholung macht dann die
            // Queue mit wachsendem Abstand. Zweimal zurueckzuweichen waere
            // einmal zu viel.
            ->retry(2, 200, function (Throwable $ausnahme): bool {
                if ($ausnahme instanceof ConnectionException) {
                    return true;
                }

                return $ausnahme instanceof RequestException
                    && $ausnahme->response->serverError();
            }, throw: false);
    }
}
