<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\AgentMode;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Konversationen fuehren und das Service-Fenster nachhalten.
 *
 * **Das Fenster zaehlt ab der letzten eingehenden Nachricht** -- 24 Stunden
 * (docs/integrationen/meta.md). Beim Senden wird es **nicht** verlaengert:
 * sonst geht es nie zu, die Praxis haelt sich fuer im Fenster, und die
 * Rechnung stimmt nicht.
 */
final class Konversationen
{
    /**
     * Die offene Konversation dieser Identitaet -- oder eine neue.
     *
     * Die Identitaet kann dabei ohne Kontakt sein: die erste Nachricht kommt
     * an, bevor jemand weiss, wer da schreibt (Entscheidung D5).
     */
    public function fuer(ChannelIdentity $identitaet): Conversation
    {
        $offene = Conversation::query()
            ->offen()
            ->where('channel_identity_id', $identitaet->getKey())
            ->orderByDesc('last_inbound_at')
            ->first();

        if ($offene instanceof Conversation) {
            return $offene;
        }

        $konversation = new Conversation;
        $konversation->channel_identity_id = $identitaet->getKey();
        $konversation->contact_id = $identitaet->contact_id;
        $konversation->channel = $identitaet->channel;
        $konversation->status = ConversationStatus::Open;

        // **`suggest` ist die Vorgabe** (Entscheidung G2): ohne
        // Freigabemodus uebergibt keine Praxis die Kommunikation. Wer den
        // Agenten weiter laufen lassen will, stellt den Vorgabemodus in den
        // Einstellungen um -- je Gespraech laesst er sich danach jederzeit
        // aendern.
        $konversation->agent_mode = $this->vorgabemodus();

        $konversation->save();

        return $konversation;
    }

    /**
     * Nimmt eine eingehende Nachricht auf.
     *
     * Gibt null zurueck, wenn es sie schon gibt -- Meta liefert doppelt, und
     * der Unique-Index entscheidet, nicht eine Abfrage davor.
     */
    public function nimmAuf(
        Conversation $konversation,
        string $externeId,
        ?string $inhalt,
        ?string $medientyp = null,
        ?CarbonImmutable $jetzt = null,
        ?string $betreff = null,
    ): ?Message {
        $jetzt ??= CarbonImmutable::now();

        try {
            $nachricht = new Message;
            $nachricht->conversation_id = $konversation->getKey();
            $nachricht->channel = $konversation->channel;
            $nachricht->direction = MessageDirection::Inbound;
            $nachricht->status = MessageStatus::Delivered;
            $nachricht->external_id = $externeId;
            $nachricht->body = $inhalt;
            $nachricht->subject = $betreff;
            $nachricht->media_type = $medientyp;

            // Eingehendes kostet nichts -- und wird nicht geschaetzt.
            $nachricht->cost_category = MessageCostCategory::None;
            $nachricht->delivered_at = $jetzt;
            $nachricht->save();
        } catch (QueryException $ausnahme) {
            if (str_contains($ausnahme->getMessage(), 'nachricht_extern_unique')) {
                return null;
            }

            throw $ausnahme;
        }

        $this->oeffneFenster($konversation, $jetzt);

        return $nachricht;
    }

    /**
     * Setzt das Service-Fenster neu -- nur eingehend.
     *
     * Die Dauer steht in config/mrs.php (`meta.service_window_hours`), nicht
     * im Code: Meta hat sie schon einmal geaendert.
     */
    public function oeffneFenster(Conversation $konversation, CarbonImmutable $jetzt): void
    {
        $stunden = (int) config('mrs.meta.service_window_hours', 24);

        $konversation->last_inbound_at = $jetzt;

        // **Nur, wo es ein Fenster gibt.** E-Mail kennt keines (WP-20b); ein
        // Ablaufdatum in der Spalte waere eine Frist, die niemand gesetzt
        // hat -- angezeigt in der Inbox und mitgezaehlt in der Kostenanzeige.
        $konversation->service_window_expires_at = $konversation->channel->hatServicefenster()
            ? $jetzt->addHours($stunden)
            : null;

        // Eine Antwort auf eine geschlossene Konversation macht sie wieder auf.
        if ($konversation->status === ConversationStatus::Closed) {
            $konversation->status = ConversationStatus::Open;
            $konversation->closed_at = null;
        }

        $konversation->save();
    }

    /**
     * Der Modus, mit dem neue Gespraeche starten.
     *
     * Vorgabe ist `suggest` (Entscheidung G2). `off` und `auto` sind je
     * Mandant einstellbar; ein Mandant, dessen Assistent abgeschaltet ist,
     * bekommt trotzdem `suggest`-Gespraeche -- der Not-Aus greift eine Ebene
     * darueber und gilt auch fuer laufende.
     */
    private function vorgabemodus(): AgentMode
    {
        $organisation = app(TenantContext::class)->current();

        $wert = $organisation instanceof Organization
            ? data_get($organisation->settings, 'agent.default_mode')
            : null;

        return is_string($wert) ? (AgentMode::tryFrom($wert) ?? AgentMode::Suggest) : AgentMode::Suggest;
    }

    /** Nach dem Senden: der Zeitstempel wandert, das Fenster nicht. */
    public function vermerkeVersand(Conversation $konversation, CarbonImmutable $jetzt): void
    {
        $konversation->last_outbound_at = $jetzt;
        $konversation->save();
    }

    public function schliesse(Conversation $konversation, ?CarbonImmutable $jetzt = null): void
    {
        $konversation->status = ConversationStatus::Closed;
        $konversation->closed_at = $jetzt ?? CarbonImmutable::now();
        $konversation->save();
    }

    /** Alle Konversationen eines Kanals mit offenem Fenster. */
    public function imFenster(ChannelType $kanal, ?CarbonImmutable $jetzt = null): int
    {
        $jetzt ??= CarbonImmutable::now();

        return Conversation::query()
            ->where('channel', $kanal->value)
            ->where('service_window_expires_at', '>', $jetzt)
            ->count();
    }
}
