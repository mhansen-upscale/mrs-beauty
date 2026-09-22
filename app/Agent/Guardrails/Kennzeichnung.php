<?php

declare(strict_types=1);

namespace App\Agent\Guardrails;

use App\Enums\AgentAction;
use App\Models\AgentRun;
use App\Models\Conversation;

/**
 * Die Kennzeichnung der KI-Antwort (Entscheidung G6).
 *
 * **Transparenzpflicht nach EU AI Act, keine Stilfrage.** Bei der ersten
 * automatischen Antwort in einer Konversation wird kenntlich gemacht, dass
 * ein Assistent antwortet.
 *
 * **Bei `suggest` entfaellt sie**, weil ein Mensch sendet -- er hat den Text
 * gelesen, geaendert und abgeschickt, und dann ist es seine Nachricht.
 */
final class Kennzeichnung
{
    /** Ist dies die erste automatische Antwort in diesem Gespraech? */
    public function noetigFuer(Conversation $gespraech): bool
    {
        return ! AgentRun::query()
            ->where('conversation_id', $gespraech->getKey())
            ->where('action', AgentAction::Answered->value)
            ->exists();
    }

    /** Die Antwort mit Kennzeichnung -- oder unveraendert. */
    public function ergaenze(string $antwort, Conversation $gespraech): string
    {
        if (! $this->noetigFuer($gespraech)) {
            return $antwort;
        }

        $hinweis = trim((string) config('mrs.agent.disclosure'));

        return $hinweis === '' ? $antwort : $hinweis."\n\n".$antwort;
    }
}
