<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\NachrichtSenden;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Stellt eine ausgehende Nachricht ein.
 *
 * **Nie im Anfragezyklus** (Regel 4, Entscheidung A13). Faellt Meta aus,
 * bleibt die Inbox bedienbar; die Nachricht steht auf `queued` und geht
 * raus, wenn es wieder geht.
 *
 * Der Idempotenzschluessel entsteht **hier** und nicht im Job: er ist die
 * Zusage, dass zwei Laeufe desselben Auftrags eine Nachricht erzeugen und
 * nicht zwei. Ein Job, der nach einem Deploy erneut laeuft, ist der
 * Normalfall.
 */
final class Nachrichtenversand
{
    public function __construct(private readonly Konversationen $konversationen) {}

    /**
     * Reiht eine Nachricht ein.
     *
     * Gibt die vorhandene zurueck, wenn der Schluessel schon vergeben ist --
     * genau dafuer ist er da.
     */
    public function stelleEin(
        Conversation $konversation,
        string $inhalt,
        ?string $idempotenz = null,
    ): Message {
        return $this->reiheEin($konversation, $inhalt, null, [], $idempotenz);
    }

    /**
     * Reiht eine Templatenachricht ein.
     *
     * **Der einzige Weg aus einem geschlossenen Fenster** (WP-20a). Der
     * gespeicherte Rumpf ist die Vorschau mit eingesetzten Werten -- fuer den
     * Verlauf. Gesendet wird der Name des Templates mit seinen Parametern;
     * einsetzen tut WhatsApp.
     *
     * @param  list<string>  $werte
     */
    public function stelleTemplateEin(
        Conversation $konversation,
        WhatsAppTemplate $template,
        array $werte = [],
        ?string $idempotenz = null,
    ): Message {
        return $this->reiheEin($konversation, $template->vorschau($werte), $template, $werte, $idempotenz);
    }

    /**
     * @param  list<string>  $werte
     */
    private function reiheEin(
        Conversation $konversation,
        string $inhalt,
        ?WhatsAppTemplate $template,
        array $werte,
        ?string $idempotenz,
    ): Message {
        $idempotenz ??= Uuid::toString(Uuid::generate());

        try {
            $nachricht = new Message;
            $nachricht->conversation_id = $konversation->getKey();
            $nachricht->channel = $konversation->channel;
            $nachricht->direction = MessageDirection::Outbound;
            $nachricht->status = MessageStatus::Queued;
            $nachricht->body = $inhalt;
            $nachricht->idempotency_key = $idempotenz;

            if ($template instanceof WhatsAppTemplate) {
                $nachricht->template_id = $template->getKey();
                $nachricht->setzeTemplatewerte($werte);
            }

            $nachricht->save();
        } catch (QueryException $ausnahme) {
            if (! str_contains($ausnahme->getMessage(), 'nachricht_idempotenz_unique')) {
                throw $ausnahme;
            }

            // Denselben Auftrag zweimal einzureihen ist kein Fehler -- es
            // passiert nach jedem Deploy, bei jeder Wiederholung.
            return Message::query()->where('idempotency_key', $idempotenz)->firstOrFail();
        }

        // **Kanonische UUIDs, keine Rohbytes** (WP-13): der Primaerschluessel
        // ist BINARY(16) und bricht in einer Job-Nutzlast json_encode().
        NachrichtSenden::dispatch(
            (string) $nachricht->uuid,
            Uuid::toString($nachricht->organization_id),
        );

        return $nachricht;
    }

    /** Nach erfolgreichem Versand. Die Kategorie kommt vom Anbieter. */
    public function vermerkeErfolg(Message $nachricht, Versandergebnis $ergebnis, ?CarbonImmutable $jetzt = null): void
    {
        $jetzt ??= CarbonImmutable::now();

        $nachricht->external_id = $ergebnis->externeId;

        // Nur setzen, wenn der Anbieter etwas gesagt hat. Schweigen ist keine
        // Aussage ueber Kosten -- die Kategorie bleibt dann offen und kommt
        // mit der Statusrueckmeldung nach.
        if ($ergebnis->kategorie instanceof MessageCostCategory) {
            $nachricht->cost_category = $ergebnis->kategorie;
        }
        $nachricht->status = MessageStatus::Sent;
        $nachricht->sent_at = $jetzt;
        $nachricht->failure = null;
        $nachricht->save();

        $this->konversationen->vermerkeVersand($nachricht->conversation, $jetzt);
    }

    /**
     * Nach einem endgueltigen Fehlschlag.
     *
     * Ein Kurzgrund, keine Meldung des Anbieters: die traegt bei
     * Nachrichtenkanaelen regelmaessig Inhalte mit sich.
     */
    public function vermerkeFehlschlag(Message $nachricht, string $kurzgrund): void
    {
        $nachricht->status = MessageStatus::Failed;
        $nachricht->failure = $kurzgrund;
        $nachricht->cost_category ??= MessageCostCategory::None;
        $nachricht->save();
    }
}
