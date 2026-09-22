<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Schlagwort der Praxis.
 *
 * **Bewusst unverschluesselt.** Ein Schlagwort ist eine Ordnungskategorie und
 * keine Aussage ueber eine Person -- es muss sortierbar und zaehlbar bleiben.
 * Wer daraus "Botox-Stammkundin" macht, hat ein organisatorisches Problem und
 * kein technisches; die Grenze zieht die Praxis, nicht das Schema.
 *
 * @property string $name
 */
class Tag extends TenantModel
{
    use Auditable;

    protected $fillable = ['name'];

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['name'];
    }

    /**
     * @return HasMany<Taggable, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Taggable::class);
    }
}
