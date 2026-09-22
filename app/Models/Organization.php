<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBinaryUuid;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Die Mandantenwurzel.
 *
 * Kein TenantModel: sie ist der Mandant und traegt deshalb keine
 * organization_id. Entscheidung D11 -- eine Praxisgruppe ist **eine**
 * Organisation mit mehreren Standorten, nicht mehrere Organisationen. Sonst
 * entstehen doppelte Kontakte und eine falsche Auswertung.
 *
 * @property string $id Rohbytes. Die lesbare Form ist $uuid.
 * @property-read string|null $uuid
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings
 * @property CarbonImmutable|null $suspended_at
 */
class Organization extends Model
{
    use HasBinaryUuid;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    /**
     * Der gueltige Schluesselsatz. Ein widerrufener zaehlt nicht mehr.
     *
     * @return HasOne<EncryptionKey, $this>
     */
    public function encryptionKey(): HasOne
    {
        return $this->hasOne(EncryptionKey::class)->whereNull('revoked_at');
    }

    /**
     * @return HasMany<EncryptionKey, $this>
     */
    public function encryptionKeys(): HasMany
    {
        return $this->hasMany(EncryptionKey::class);
    }

    /**
     * Eine Einstellung aus settings, mit Rueckfall auf config/mrs.php.
     */
    public function setting(string $schluessel, mixed $rueckfall = null): mixed
    {
        return data_get($this->settings, $schluessel)
            ?? config("mrs.{$schluessel}", $rueckfall);
    }
}
