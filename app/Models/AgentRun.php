<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\AgentAction;
use App\Enums\AgentIntent;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Durchlauf des Agenten.
 *
 * **Der Nachweis, nicht das Log.** Einer Praxis muss sich erklaeren lassen,
 * warum ihr Agent etwas vorgeschlagen -- oder eben nicht vorgeschlagen -- hat
 * (docs/fachlogik/agent.md, Protokollierung).
 *
 * **Bewusst ohne Auditable.** Das Protokoll aus WP-05 haelt fest, was
 * Menschen tun. Ein Eintrag je Nachricht wuerde es fluten, und der Nachweis
 * steht ohnehin hier.
 *
 * @property AgentIntent|null $intent
 * @property AgentAction $action
 * @property float|null $confidence
 * @property string|null $escalation_reason
 * @property string|null $entities
 * @property string|null $suggestion
 * @property array<int, string>|null $guardrails
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $cost_tenth_cents
 * @property int|null $duration_ms
 * @property string|null $failure
 * @property string $conversation_id
 * @property string|null $message_id
 * @property CarbonImmutable|null $created_at
 */
class AgentRun extends TenantModel implements HasPersonalData
{
    use MasksPersonalData;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['conversation_id', 'message_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intent' => AgentIntent::class,
            'action' => AgentAction::class,
            'confidence' => 'float',
            'entities' => Encrypted::class,
            'suggestion' => Encrypted::class,
            'guardrails' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_tenth_cents' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * In den Entitaeten steht der Name einer Person, im Vorschlag ein Text,
     * der an sie hinausgehen soll.
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['entities', 'suggestion'];
    }

    /**
     * Die erkannten Entitaeten.
     *
     * @return array<string, mixed>
     */
    public function entitaeten(): array
    {
        if (! is_string($this->entities) || $this->entities === '') {
            return [];
        }

        $werte = json_decode($this->entities, true);

        return is_array($werte) ? $werte : [];
    }

    /**
     * @param  array<string, mixed>  $werte
     */
    public function setzeEntitaeten(array $werte): void
    {
        $this->entities = $werte === [] ? null : (string) json_encode($werte);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
