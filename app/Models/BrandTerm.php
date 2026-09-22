<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BrandTermKind;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ein Begriff, den diese Praxis bevorzugt oder vermeidet.
 *
 * @property BrandTermKind $kind
 * @property string $term
 * @property string|null $replacement
 * @property string|null $reason
 */
class BrandTerm extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BrandTermKind::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['kind', 'term'];
    }

    /**
     * @param  Builder<BrandTerm>  $query
     * @return Builder<BrandTerm>
     */
    public function scopeArt(Builder $query, BrandTermKind $art): Builder
    {
        return $query->where('kind', $art->value);
    }
}
