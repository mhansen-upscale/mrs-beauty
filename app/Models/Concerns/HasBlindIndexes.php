<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Contracts\UsesBlindIndexes;
use App\Support\BlindIndex;
use App\Tenancy\KeyRing;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Haelt zu verschluesselten Feldern einen blinden Index nach (Entscheidung A6).
 *
 * Das Modell beschreibt die Zuordnung:
 *
 *     public function blindIndexes(): array
 *     {
 *         return ['email' => 'email_bidx'];
 *     }
 *
 * @mixin Model
 */
trait HasBlindIndexes
{
    public function initializeHasBlindIndexes(): void
    {
        // Ein blinder Index ist ein HMAC in Rohbytes. Er gehoert weder in JSON
        // noch in die Oberflaeche -- und er bricht json_encode().
        $this->hidden = array_values(array_unique([
            ...$this->hidden,
            ...array_values($this->blindIndexes()),
        ]));
    }

    public static function bootHasBlindIndexes(): void
    {
        static::saving(function (UsesBlindIndexes&Model $model): void {
            foreach ($model->blindIndexes() as $feld => $spalte) {
                $wert = $model->getAttribute($feld);

                $model->setAttribute(
                    $spalte,
                    is_string($wert) && $wert !== ''
                        ? BlindIndex::hash($wert, self::blindIndexKeyFor($model))
                        : null
                );
            }
        });
    }

    /**
     * Suche ueber den exakten Wert eines verschluesselten Feldes.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function scopeWhereBlind(Builder $query, string $feld, string $wert): Builder
    {
        $spalte = $this->blindIndexes()[$feld]
            ?? throw new \InvalidArgumentException(
                "Fuer {$feld} ist kein blinder Index eingerichtet."
            );

        return $query->where($spalte, BlindIndex::hash($wert, self::blindIndexKeyFor($this)));
    }

    private static function blindIndexKeyFor(Model $model): string
    {
        $organizationId = $model->getAttribute('organization_id');

        if (! is_string($organizationId) || $organizationId === '') {
            $organizationId = app(TenantContext::class)->requireId();
        }

        return app(KeyRing::class)->for($organizationId)->blindIndexKey;
    }
}
