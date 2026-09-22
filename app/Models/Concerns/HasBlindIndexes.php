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
                $wert = $model->blindIndexValue($feld, $model->getAttribute($feld));

                $model->setAttribute(
                    $spalte,
                    $wert === null
                        ? null
                        : BlindIndex::hash($wert, self::blindIndexKeyFor($model))
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

        $vergleichbar = $this->blindIndexValue($feld, $wert);

        // Was sich nicht in die vergleichbare Form bringen laesst, hat auch
        // keinen Index -- die Suche darf dann nichts finden statt irgendetwas.
        if ($vergleichbar === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($spalte, BlindIndex::hash($vergleichbar, self::blindIndexKeyFor($this)));
    }

    /**
     * Der Index, wie er zu diesem Datensatz gehoert -- oder null.
     *
     * Rechnet, ohne etwas anzufassen. Wer das verschluesselte Quellfeld neu
     * setzt, um den saving-Haken auszuloesen, schreibt es mit neuem Nonce
     * zurueck: der Datensatz gilt als geaendert, obwohl sich nichts geaendert
     * hat. Fuer einen Nachtragslauf ueber alle Zeilen ist das der Unterschied
     * zwischen idempotent und nicht.
     */
    public function blindIndexHash(string $feld): ?string
    {
        $wert = $this->blindIndexValue($feld, $this->getAttribute($feld));

        return $wert === null ? null : BlindIndex::hash($wert, self::blindIndexKeyFor($this));
    }

    /**
     * Der Wert, ueber den der Index gebildet wird.
     *
     * Vorgabe ist der Wert selbst; BlindIndex::hash() kuerzt und kleinschreibt
     * ihn. Ein Modell kann das ueberschreiben, wo die Gleichheit mehr verlangt
     * als Kleinschreibung -- eine Telefonnummer etwa muss vorher nach E.164,
     * sonst sind "+49 170 1234567" und "01701234567" zwei verschiedene
     * Personen (WP-16).
     *
     * Null heisst: kein Index. Der Wert wird trotzdem gespeichert.
     */
    public function blindIndexValue(string $feld, mixed $wert): ?string
    {
        return is_string($wert) && $wert !== '' ? $wert : null;
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
