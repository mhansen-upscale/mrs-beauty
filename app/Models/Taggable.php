<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Die Zuordnung eines Schlagworts.
 *
 * Eigenes Modell statt einer blossen Pivot-Tabelle, weil die Zuordnung
 * mandantengebunden ist wie alles andere auch -- ein Schlagwort der einen
 * Praxis darf an keinem Datensatz der anderen haengen (Regel 1).
 *
 * @property string $tag_id
 * @property string $taggable_type
 * @property string $taggable_id
 * @property-read Tag $tag
 */
class Taggable extends TenantModel
{
    protected $fillable = ['tag_id', 'taggable_type', 'taggable_id'];

    /** @var list<string> */
    protected $hidden = ['tag_id', 'taggable_id'];

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }
}
