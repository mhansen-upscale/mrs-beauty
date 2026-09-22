<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Das Protokoll. Append-only (Entscheidung C5).
 *
 * Die eigentliche Sperre liegt in zwei Datenbank-Triggern -- diese Klasse
 * sorgt nur dafuer, dass der Fehler frueh und verstaendlich kommt statt als
 * SQLSTATE 45000 aus der Tiefe.
 *
 * **Hier stehen keine Klartext-Personendaten.** Protokolliert wird, wer wann
 * was an welchem Datensatz getan hat. `changed_fields` nennt Feldnamen,
 * `context` nur Werte von Feldern, die das jeweilige Modell ausdruecklich als
 * unbedenklich erklaert hat.
 *
 * @property string|null $organization_id Rohbytes, null bei mandantenuebergreifenden Vorgaengen.
 * @property AuditEvent $event
 * @property array<int, string>|null $changed_fields
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $occurred_at
 */
class AuditLog extends TenantModel
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'changed_fields' => 'array',
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @var list<string> */
    protected $hidden = [
        'actor_user_id',
        'subject_id',
        'impersonation_session_id',
    ];

    public static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'audit_logs ist append-only (Entscheidung C5). Ein Eintrag wird '
                .'nicht geaendert -- ein Protokoll, das sich aendern laesst, ist keines.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'audit_logs ist append-only (Entscheidung C5). Geloescht wird nur '
                .'ueber den Aufbewahrungsjob nach 36 Monaten (Entscheidung C7).'
            );
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
