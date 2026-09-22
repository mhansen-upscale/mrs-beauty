<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentMode;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Gespraech ueber einen Kanal.
 *
 * **Das Service-Fenster zaehlt ab der letzten eingehenden Nachricht** -- 24
 * Stunden, danach ist nur ein genehmigtes Template moeglich, und das kostet
 * (docs/integrationen/meta.md). Beim Senden wird es **nicht** verlaengert;
 * sonst geht es nie zu, und die Rechnung stimmt nicht.
 *
 * @property ChannelType $channel
 * @property ConversationStatus $status
 * @property AgentMode $agent_mode
 * @property string $channel_identity_id
 * @property string|null $contact_id
 * @property CarbonImmutable|null $agent_paused_until
 * @property CarbonImmutable|null $service_window_expires_at
 * @property CarbonImmutable|null $last_inbound_at
 * @property CarbonImmutable|null $last_outbound_at
 * @property CarbonImmutable|null $last_read_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $anonymized_at
 * @property CarbonImmutable|null $created_at
 * @property-read ChannelIdentity $channelIdentity
 */
class Conversation extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['channel_identity_id', 'contact_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'status' => ConversationStatus::class,
            'agent_mode' => AgentMode::class,
            'agent_paused_until' => 'immutable_datetime',
            'service_window_expires_at' => 'immutable_datetime',
            'last_inbound_at' => 'immutable_datetime',
            'last_outbound_at' => 'immutable_datetime',
            'last_read_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
        ];
    }

    /**
     * Kanal, Zustand und Agentenmodus duerfen mit Wert ins Protokoll -- keiner
     * sagt etwas ueber eine Person (Entscheidung C5). Wer geschrieben hat,
     * bleibt draussen.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['channel', 'status', 'agent_mode'];
    }

    /**
     * Ist hier etwas hereingekommen, das noch niemand gesehen hat?
     *
     * Verglichen wird mit der letzten **eingehenden** Nachricht: was wir
     * selbst geschrieben haben, muss niemand lesen.
     *
     * Gleichstand gilt als gelesen. Die Spalten zaehlen Sekunden, und der
     * haeufige Fall ist der, dass jemand ein Gespraech in dem Augenblick
     * oeffnet, in dem die Nachricht ankommt -- eine Zahl, die dabei stehen
     * bliebe, liesse sich nie auf null bringen.
     */
    public function ungelesen(): bool
    {
        if (! $this->last_inbound_at instanceof CarbonImmutable) {
            return false;
        }

        return ! $this->last_read_at instanceof CarbonImmutable
            || $this->last_read_at->lessThan($this->last_inbound_at);
    }

    /** Laesst sich jetzt ohne Template antworten? */
    public function fensterOffen(?CarbonImmutable $jetzt = null): bool
    {
        $jetzt ??= CarbonImmutable::now();

        return $this->service_window_expires_at !== null
            && $this->service_window_expires_at->greaterThan($jetzt);
    }

    /** Wie lange noch, in Minuten. Null heisst: geschlossen. */
    public function fensterRestminuten(?CarbonImmutable $jetzt = null): ?int
    {
        $jetzt ??= CarbonImmutable::now();

        return $this->fensterOffen($jetzt)
            ? (int) $jetzt->diffInMinutes($this->service_window_expires_at, absolute: true)
            : null;
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeOffen(Builder $query): Builder
    {
        return $query->where('status', ConversationStatus::Open->value);
    }

    /**
     * @return BelongsTo<ChannelIdentity, $this>
     */
    public function channelIdentity(): BelongsTo
    {
        return $this->belongsTo(ChannelIdentity::class, 'channel_identity_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
