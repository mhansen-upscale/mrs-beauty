<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * UUID v7 als BINARY(16) (Entscheidung A4).
 *
 * `id` bleibt bewusst binaer. Eloquent reicht die Bytes durch, damit
 * Beziehungen, Eager Loading, whereIn und Factories ohne Sonderbehandlung
 * arbeiten. Die lesbare Form heisst `uuid` und ist die Form fuer Oberflaeche,
 * Protokoll und URL.
 *
 * Der Preis steht in docs/datenmodell.md, Abschnitt 0.5: `$model->id` liefert
 * Rohbytes. Wer sie in ein Log schreibt, schreibt Unsinn hinein.
 *
 * @mixin Model
 */
trait HasBinaryUuid
{
    public static function bootHasBinaryUuid(): void
    {
        static::creating(function (Model $model): void {
            $schluessel = $model->getKeyName();

            if (blank($model->getAttribute($schluessel))) {
                $model->setAttribute($schluessel, Uuid::generate());
            }
        });
    }

    public function initializeHasBinaryUuid(): void
    {
        // Rohbytes gehoeren nicht in JSON. Stattdessen die kanonische Form.
        $this->hidden = array_values(array_unique([...$this->hidden, $this->getKeyName()]));
        $this->appends = array_values(array_unique([...$this->appends, 'uuid']));
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    /**
     * Kanonische Form des Primaerschluessels.
     *
     * @return Attribute<string|null, never>
     */
    protected function uuid(): Attribute
    {
        return Attribute::get(function (): ?string {
            $roh = $this->getAttribute($this->getKeyName());

            return is_string($roh) && $roh !== '' ? Uuid::toString($roh) : null;
        })->shouldCache();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if (! is_string($value) || ! Uuid::isCanonical($value)) {
            return null;
        }

        $spalte = $field === null || $field === 'uuid' ? $this->getKeyName() : $field;

        return $this->newQuery()->where($spalte, Uuid::toBinary($value))->first();
    }

    /**
     * Vergleich gegen eine ID in kanonischer oder binaerer Form.
     *
     * @param  Builder<covariant Model>  $query
     * @param  string|array<int, string>  $uuid
     * @return Builder<covariant Model>
     */
    public function scopeWhereUuid(Builder $query, string|array $uuid, ?string $column = null): Builder
    {
        $spalte = $column ?? $this->getKeyName();

        if (is_array($uuid)) {
            return $query->whereIn($spalte, array_map(Uuid::normalize(...), $uuid));
        }

        return $query->where($spalte, Uuid::normalize($uuid));
    }
}
