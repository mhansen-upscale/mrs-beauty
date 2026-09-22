<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InsightLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Metas Zahlen eines Tages.
 *
 * **Nur Grundwerte.** Quoten und Summen rechnet `App\Werbung\Kennzahlen` --
 * an einer Stelle, aus diesen Zeilen. Eine gespeicherte CTR waere die zweite
 * Zahl fuer dieselbe Aussage.
 *
 * Kein Fremdschluessel auf die Kampagne: die Zahlen ueberleben eine Kampagne,
 * die bei Meta verschwindet, und die Zuordnung laeuft ueber external_id.
 *
 * @property InsightLevel $level
 * @property string $external_id
 * @property CarbonImmutable $stat_date
 * @property int $spend_minor
 * @property int $impressions
 * @property int $clicks
 * @property int $link_clicks
 * @property int $leads
 * @property CarbonImmutable|null $synced_at
 */
class AdInsight extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['ad_account_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => InsightLevel::class,
            'stat_date' => 'immutable_date',
            'spend_minor' => 'integer',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'link_clicks' => 'integer',
            'leads' => 'integer',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<AdInsight>  $query
     * @return Builder<AdInsight>
     */
    public function scopeEbene(Builder $query, InsightLevel $ebene): Builder
    {
        return $query->where('level', $ebene->value);
    }

    /**
     * @param  Builder<AdInsight>  $query
     * @return Builder<AdInsight>
     */
    public function scopeZeitraum(Builder $query, CarbonImmutable $von, CarbonImmutable $bis): Builder
    {
        return $query->whereBetween('stat_date', [$von->toDateString(), $bis->toDateString()]);
    }
}
