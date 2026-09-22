<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Models\Concerns\Auditable;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Zusammenfuehrung zweier Kontakte -- mit Snapshot, umkehrbar
 * (Entscheidung D7).
 *
 * Zwei Datensaetze derselben Person sind aergerlich und reparierbar. Zwei
 * Personen in einem Datensatz sind ein Datenschutzvorfall, und in einer
 * aesthetischen Praxis heisst das: jemand sieht die Termine eines anderen.
 * Deshalb ist jede Zusammenfuehrung umkehrbar.
 *
 * **Der Snapshot enthaelt selbst Personendaten** und liegt deshalb
 * verschluesselt und befristet. Nach Ablauf bleibt der Vorgang sichtbar, aber
 * nicht mehr umkehrbar -- das gehoert in die Oberflaeche, nicht in eine
 * Fussnote.
 *
 * @property string $winner_contact_id
 * @property string $loser_contact_id Rohbytes; der Kontakt ist geloescht (A12).
 * @property string|null $merged_by_user_id
 * @property string|null $snapshot JSON, verschluesselt
 * @property CarbonImmutable $snapshot_expires_at
 * @property CarbonImmutable|null $reverted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Contact $winner
 */
class ContactMerge extends TenantModel implements HasPersonalData
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['winner_contact_id', 'loser_contact_id', 'snapshot', 'merged_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => Encrypted::class,
            'snapshot_expires_at' => 'immutable_datetime',
            'reverted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['snapshot'];
    }

    /**
     * Nichts. Ein Protokolleintrag mit Snapshot waere eine zweite,
     * unverschluesselte Datenhaltung (Entscheidung C5).
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return [];
    }

    /** Laesst sich dieser Vorgang noch umkehren? */
    public function istUmkehrbar(?CarbonImmutable $jetzt = null): bool
    {
        $jetzt ??= CarbonImmutable::now();

        return $this->reverted_at === null
            && $this->snapshot !== null
            && $this->snapshot_expires_at->greaterThan($jetzt);
    }

    /**
     * Der Inhalt des Snapshots.
     *
     * @return array<string, mixed>
     */
    public function inhalt(): array
    {
        $roh = is_string($this->snapshot) ? json_decode($this->snapshot, true) : null;

        return is_array($roh) ? $roh : [];
    }

    public function loserUuid(): string
    {
        return Uuid::toString($this->loser_contact_id);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'winner_contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by_user_id');
    }
}
