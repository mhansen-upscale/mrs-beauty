<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataSubjectRequestStatus;
use App\Enums\DataSubjectRequestType;
use App\Models\Concerns\Auditable;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Betroffenenrecht: Auskunft, Berichtigung, Loeschung.
 *
 * **Der Vorgang ueberlebt den Kontakt**, und das ist der Punkt: nach einer
 * Loeschung ist er der Nachweis, dass geloescht wurde. Deshalb steht die
 * Kontaktkennung hier ohne Fremdschluessel.
 *
 * `result` haelt Zahlen, keine Daten. Ein Nachweis der Loeschung, der die
 * geloeschten Daten enthaelt, ist keiner.
 *
 * @property string $contact_id Rohbytes; der Kontakt kann geloescht sein.
 * @property string|null $requested_by_user_id
 * @property DataSubjectRequestType $type
 * @property DataSubjectRequestStatus $status
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 */
class DataSubjectRequest extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['contact_id', 'requested_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DataSubjectRequestType::class,
            'status' => DataSubjectRequestStatus::class,
            'result' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Art und Zustand duerfen mit Wert ins Protokoll -- ohne sie waere nicht
     * belegbar, dass einem Verlangen entsprochen wurde.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['type', 'status'];
    }

    public function contactUuid(): string
    {
        return Uuid::toString($this->contact_id);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
