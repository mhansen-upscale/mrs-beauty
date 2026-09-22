<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\SyncState;
use App\Models\Concerns\GehoertZurWerbestruktur;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Anzeigengruppe -- Metas mittlere Ebene, hier gelesen.
 *
 * Die Zielgruppendefinition wird **nicht** uebernommen. Sie enthaelt
 * Interessen und Merkmale, die im Umfeld einer aesthetischen Praxis
 * gesundheitsnah sind, und dieses Paket braucht sie fuer nichts (Regel 3:
 * was nicht gebraucht wird, wird nicht gespeichert).
 *
 * @property string $ad_account_id
 * @property string $ad_campaign_id
 * @property string $external_id
 * @property string|null $name
 * @property string|null $status
 * @property string|null $effective_status
 * @property SyncState $sync_state
 * @property string|null $sync_error
 * @property string|null $client_token
 * @property bool $managed_by_us
 * @property string|null $optimization_goal
 * @property int|null $daily_budget
 * @property int|null $lifetime_budget
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $stops_at
 * @property CarbonImmutable|null $synced_at
 * @property string|null $location_id
 * @property int|null $radius_km
 * @property int|null $age_min
 * @property int|null $age_max
 * @property string|null $genders
 * @property CarbonImmutable|null $vanished_at
 */
class AdSet extends TenantModel implements HasPersonalData
{
    use GehoertZurWerbestruktur;
    use MasksPersonalData;

    protected $table = 'ad_sets';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['ad_account_id', 'ad_campaign_id', 'location_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Encrypted::class,
            'sync_state' => SyncState::class,
            'managed_by_us' => 'boolean',
            'radius_km' => 'integer',
            'age_min' => 'integer',
            'age_max' => 'integer',
            'daily_budget' => 'integer',
            'lifetime_budget' => 'integer',
            'starts_at' => 'immutable_datetime',
            'stops_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'vanished_at' => 'immutable_datetime',
        ];
    }

    /**
     * Der Name traegt die Katalogbezeichnung, wenn die Praxis ihn so gewaehlt
     * hat -- "Botox Herbst" ist ein Behandlungshinweis. Deshalb faellt er
     * unter die Maskierung (Entscheidung C4): eine Supportkraft sieht ihn
     * ohne Freigabe nicht.
     *
     * Er gehoert damit zur selben Klasse wie ein Nachrichtentext, auch wenn
     * er keinem Menschen zuzuordnen ist. Eine Ausnahme von der Regel aus
     * WP-05 waere billiger und liesse die Regel mit einem Loch zurueck.
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['name'];
    }

    /** @return BelongsTo<AdCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    /** @return HasMany<Ad, $this> */
    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class, 'ad_set_id');
    }
}
