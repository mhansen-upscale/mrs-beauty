<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Bildformat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Grafik eines Entwurfs, in einem Format (WP-31b).
 *
 * **Die Datei ist ein Anhang**, verschluesselt und virengeprueft wie jeder
 * andere. Dieser Datensatz sagt nur, welches Format sie hat und zu welchem
 * Satz sie gehoert -- drei Formate aus einem Auftrag sind eine Grafik, und
 * so wird auch gezaehlt (B13).
 *
 * @property string $ad_suggestion_id
 * @property string $attachment_id
 * @property Bildformat $format
 * @property string $batch Rohbytes, wie die Schluessel
 * @property CarbonImmutable|null $created_at
 */
class AdSuggestionImage extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['ad_suggestion_id', 'attachment_id', 'batch'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => Bildformat::class,
        ];
    }

    /** @return BelongsTo<AdSuggestion, $this> */
    public function vorschlag(): BelongsTo
    {
        return $this->belongsTo(AdSuggestion::class, 'ad_suggestion_id');
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
