<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Anfrage (Entscheidung D3).
 *
 * **Nicht der Kontakt.** Mehrfachanfragen sind der Normalfall, und die
 * Attribution braucht die Anfrage als Einheit: welche Anzeige welche Buchung
 * gebracht hat, laesst sich am Kontakt nicht mehr feststellen.
 *
 * **Bewusst ohne Freitext** (Entscheidung D2). Der Behandlungswunsch ist eine
 * `treatment_id`; ein Textfeld an dieser Stelle fuellt sich mit Angaben nach
 * Artikel 9 DSGVO. Notizen mit Zweckbindung und Frist gehoeren zu WP-18.
 *
 * @property string $contact_id
 * @property string|null $treatment_id
 * @property LeadStatus $status
 * @property LeadSource $source
 * @property LeadLostReason|null $lost_reason
 * @property CarbonImmutable|null $first_responded_at
 * @property int|null $first_response_seconds
 * @property CarbonImmutable $last_activity_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Contact $contact
 * @property-read Treatment|null $treatment
 */
class Lead extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['contact_id', 'treatment_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'lost_reason' => LeadLostReason::class,
            'first_responded_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Status, Quelle und Grund duerfen mit Wert ins Protokoll -- keiner der
     * drei sagt etwas ueber eine Person (Entscheidung C5). Wer der Kontakt
     * ist, bleibt draussen.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['status', 'source', 'lost_reason'];
    }

    /**
     * Haelt die erste Reaktion fest -- und nur die erste.
     *
     * Speed-to-Lead ist der Median ueber first_response_seconds
     * (docs/fachlogik/attribution.md). Eine Kennzahl, die sich durch
     * Nacharbeit verbessern laesst, ist keine.
     */
    public function vermerkeReaktion(CarbonImmutable $jetzt): void
    {
        if ($this->first_responded_at !== null) {
            return;
        }

        $entstanden = $this->created_at ?? $jetzt;

        $this->first_responded_at = $jetzt;
        $this->first_response_seconds = (int) max(0, $entstanden->diffInSeconds($jetzt, absolute: true));
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeOffen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LeadStatus::New->value,
            LeadStatus::Contacted->value,
            LeadStatus::Scheduled->value,
        ]);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }
}
