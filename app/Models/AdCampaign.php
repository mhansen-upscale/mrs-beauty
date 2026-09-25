<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\SyncState;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\GehoertZurWerbestruktur;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine Kampagne bei Meta -- gelesen, nicht geschrieben.
 *
 * **Der Name liegt verschluesselt.** Eine importierte Kampagne kann "Botox
 * Herbst" heissen: die Praxis hat sie so benannt, bevor sie uns kannte, und
 * wir aendern fremde Namen nicht. Ab WP-32 friert dieser Name als
 * attribution_snapshot am Termin ein (D13) -- dann steht ein Behandlungsname
 * in einem offenen Feld unmittelbar neben einem Kontakt.
 *
 * Sortiert und gesucht wird deshalb in PHP, nicht in SQL. Bei einigen Dutzend
 * Kampagnen je Praxis ist das die billigere Haelfte des Handels (wie P8).
 *
 * @property string $ad_account_id
 * @property string $external_id
 * @property string|null $name
 * @property string|null $status
 * @property string|null $effective_status
 * @property SyncState $sync_state
 * @property string|null $sync_error
 * @property string|null $client_token
 * @property bool $managed_by_us
 * @property string|null $objective
 * @property int|null $daily_budget
 * @property int|null $lifetime_budget
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $stops_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $vanished_at
 * @property CarbonImmutable|null $deleting_at
 * @property-read AdAccount $account
 */
class AdCampaign extends TenantModel implements HasPersonalData
{
    use Auditable;
    use GehoertZurWerbestruktur;
    use MasksPersonalData;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['ad_account_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Encrypted::class,
            'sync_state' => SyncState::class,
            'managed_by_us' => 'boolean',
            'daily_budget' => 'integer',
            'lifetime_budget' => 'integer',
            'starts_at' => 'immutable_datetime',
            'stops_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'vanished_at' => 'immutable_datetime',
            'deleting_at' => 'immutable_datetime',
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

    /**
     * **Jede Aenderung an einer laufenden Kampagne ist Geld.** Zustand und
     * Budget duerfen deshalb mit Wert ins Protokoll -- keiner von beiden sagt
     * etwas ueber eine Person (Entscheidung C5). Der Name bleibt draussen:
     * er kann eine Behandlungsbezeichnung tragen.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['status', 'daily_budget', 'sync_state'];
    }

    /** @return BelongsTo<AdAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    /** @return HasMany<AdSet, $this> */
    public function sets(): HasMany
    {
        return $this->hasMany(AdSet::class, 'ad_campaign_id');
    }
}
