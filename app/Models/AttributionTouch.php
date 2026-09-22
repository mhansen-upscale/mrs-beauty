<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ein Aufruf der Buchungsseite, den jemand erlaubt hat.
 *
 * **Gespeichert wird weniger, als man koennte.** Vom Verweis bleibt der Host,
 * von der Adresse der Pfad -- der Abfrageteil traegt im Zweifel eine
 * Behandlung, und sobald contact_id gesetzt ist, stuende sie unverschluesselt
 * neben einem Kontakt (Regel 3).
 *
 * @property string $visitor_id
 * @property string|null $click_id
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 * @property string|null $campaign_external_id
 * @property string|null $adset_external_id
 * @property string|null $ad_external_id
 * @property string|null $landing_path
 * @property string|null $referrer_host
 * @property CarbonImmutable $occurred_at
 * @property string|null $contact_id
 * @property string|null $lead_id
 */
class AttributionTouch extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['contact_id', 'lead_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * Traegt dieser Touch eine erkennbare Quelle?
     *
     * **Ein Direktaufruf ist keine Quelle, sondern das Fehlen einer.** Genau
     * darauf beruht Last-Non-Direct.
     */
    public function hatQuelle(): bool
    {
        return $this->click_id !== null
            || $this->utm_source !== null
            || $this->campaign_external_id !== null
            || $this->referrer_host !== null;
    }

    /**
     * @param  Builder<AttributionTouch>  $query
     * @return Builder<AttributionTouch>
     */
    public function scopeFuerBesucher(Builder $query, string $besucher): Builder
    {
        return $query->where('visitor_id', $besucher);
    }
}
