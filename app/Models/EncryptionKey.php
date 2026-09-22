<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBinaryUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Der umschlossene Schluesselsatz einer Organisation (Entscheidung A5).
 *
 * **Bewusst kein TenantModel**, obwohl die Tabelle eine organization_id traegt.
 * Zwei Gruende:
 *
 * 1. Der Schluessel wird gebraucht, um Mandantendaten ueberhaupt zu lesen. Ein
 *    Global Scope, der selbst wieder einen Mandantenkontext verlangt, waere
 *    ein Kreisschluss.
 * 2. Der Zugriff ist auf App\Tenancy\KeyRing beschraenkt und laeuft dort immer
 *    ueber eine ausdrueckliche organization_id.
 *
 * Diese Ausnahme steht in der Zulassungsliste des Architektur-Tests.
 *
 * @property string $id
 * @property-read string|null $uuid
 * @property string $organization_id
 * @property string $wrapped_dek
 * @property string $wrapped_index_key
 */
class EncryptionKey extends Model
{
    use HasBinaryUuid;

    protected $guarded = ['id'];

    /**
     * Dieses Modell hat in keiner Antwort etwas verloren. Die Liste steht
     * trotzdem hier, damit ein versehentliches toArray() nicht die
     * umschlossenen Schluessel ausliefert -- und damit json_encode() nicht an
     * den Rohbytes bricht.
     *
     * @var list<string>
     */
    protected $hidden = [
        'organization_id',
        'active_guard',
        'wrapped_dek',
        'wrapped_index_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
