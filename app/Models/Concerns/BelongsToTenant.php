<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Uuid;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bindet ein Modell an eine Organisation (Entscheidung A1).
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            // array_key_exists statt blank(): ein ausdrueckliches null bleibt
            // stehen. Gebraucht wird das nur von audit_logs, wo ein
            // mandantenuebergreifender Vorgang zu keiner Organisation gehoert.
            // Fuer jede andere Tabelle faengt das NOT NULL der Datenbank einen
            // Fehlgriff ab.
            if (! array_key_exists('organization_id', $model->getAttributes())) {
                $model->setAttribute(
                    'organization_id',
                    app(TenantContext::class)->requireId($model::class)
                );
            }
        });

        // Ein Datensatz wechselt nicht den Mandanten. Ohne diese Sperre waere
        // ein Tippfehler in einem Update ein Datenleck.
        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw TenantMismatch::beimUmhaengen($model::class);
            }
        });
    }

    public function initializeBelongsToTenant(): void
    {
        // Rohbytes gehoeren nicht in JSON. Ohne dies bricht json_encode() an
        // der ersten Inertia-Antwort, die das Modell teilt -- mit einer
        // Meldung, die auf alles ausser die Ursache zeigt.
        $this->hidden = array_values(array_unique([...$this->hidden, 'organization_id']));
        $this->appends = array_values(array_unique([...$this->appends, 'organization_uuid']));
    }

    /**
     * Kanonische Form der organization_id.
     *
     * @return Attribute<string|null, never>
     */
    protected function organizationUuid(): Attribute
    {
        return Attribute::get(function (): ?string {
            $roh = $this->getAttribute('organization_id');

            return is_string($roh) && $roh !== '' ? Uuid::toString($roh) : null;
        })->shouldCache();
    }

    /**
     * organization_id ist nie massenzuweisbar -- auch dann nicht, wenn ein
     * spaeteres Paket sie versehentlich in $fillable auflistet.
     */
    public function isFillable($key): bool
    {
        if ($key === 'organization_id') {
            return false;
        }

        return parent::isFillable($key);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
