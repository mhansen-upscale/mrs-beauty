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

/**
 * Eine Anzeige.
 *
 * **Gelesen aus Metas Bestand -- oder von uns angelegt.** Der zweite Fall
 * kam mit WP-27b dazu: aus einem freigegebenen Entwurf mit Grafik (WP-31)
 * wird ein Creative und eine Anzeige. Die Spur dorthin steht in
 * `ad_suggestion_id`; ohne sie liesse sich spaeter nicht sagen, welcher
 * geprueefte Text auf welcher Anzeige steht.
 *
 * @property string $ad_account_id
 * @property string $ad_set_id
 * @property string $external_id
 * @property string|null $name
 * @property string|null $status
 * @property string|null $effective_status
 * @property string|null $creative_external_id
 * @property SyncState $sync_state
 * @property string|null $sync_error
 * @property string|null $client_token
 * @property bool $managed_by_us
 * @property string|null $image_hash
 * @property string|null $ad_suggestion_id
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $vanished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Ad extends TenantModel implements HasPersonalData
{
    use GehoertZurWerbestruktur;
    use MasksPersonalData;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['ad_account_id', 'ad_set_id', 'ad_suggestion_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Encrypted::class,
            'sync_state' => SyncState::class,
            'managed_by_us' => 'boolean',
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

    /** @return BelongsTo<AdSet, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(AdSet::class, 'ad_set_id');
    }

    /** @return BelongsTo<AdSuggestion, $this> */
    public function vorschlag(): BelongsTo
    {
        return $this->belongsTo(AdSuggestion::class, 'ad_suggestion_id');
    }
}
