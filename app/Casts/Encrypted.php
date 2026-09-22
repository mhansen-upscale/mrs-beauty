<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\FieldCipher;
use App\Tenancy\KeyRing;
use App\Tenancy\OrganizationKeys;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Feldverschluesselung mit dem Schluessel der Organisation (Entscheidung A6).
 *
 * Der Schluessel richtet sich nach der organization_id **des Datensatzes**,
 * nicht nach dem gerade gesetzten Mandanten. Sonst liesse sich ein Datensatz
 * innerhalb von acrossTenants() nicht mehr lesen.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class Encrypted implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return FieldCipher::decrypt($value, $this->keys($attributes)->dataEncryptionKey);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        return [
            $key => FieldCipher::encrypt(
                (string) $value,
                $this->keys($attributes)->dataEncryptionKey
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function keys(array $attributes): OrganizationKeys
    {
        $organizationId = $attributes['organization_id'] ?? null;

        if (! is_string($organizationId) || $organizationId === '') {
            // Beim Anlegen ist organization_id noch nicht gesetzt: der Cast
            // greift beim Zuweisen, der creating-Haken erst beim Speichern.
            $organizationId = app(TenantContext::class)->requireId();
        }

        return app(KeyRing::class)->for($organizationId);
    }
}
