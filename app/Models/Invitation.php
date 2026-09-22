<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HasPersonalData;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Eine offene Einladung in eine Organisation.
 *
 * @property string $email
 * @property Role $role
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 */
class Invitation extends TenantModel implements HasPersonalData
{
    use Auditable;

    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    use MasksPersonalData;

    protected $fillable = [
        'email',
        'role',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'invited_by_user_id',
        'token_hash',
        'open_guard',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['email'];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['role'];
    }

    /**
     * Erzeugt ein Merkmal und gibt es **einmal** im Klartext zurueck.
     *
     * In der Datenbank steht nur der Hash. Wer die Datenbank liest, koennte
     * sonst jede offene Einladung annehmen.
     */
    public static function erzeugeMerkmal(): string
    {
        return Str::random(48);
    }

    public static function hashe(string $merkmal): string
    {
        return hash('sha256', $merkmal);
    }

    /**
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeOffen(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function istOffen(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /** Warum die Einladung nicht mehr gilt -- fuer eine verstaendliche Meldung. */
    public function grundDerUngueltigkeit(): ?string
    {
        return match (true) {
            $this->accepted_at !== null => 'Diese Einladung wurde bereits angenommen.',
            $this->revoked_at !== null => 'Diese Einladung wurde zurueckgezogen.',
            $this->expires_at->isPast() => 'Diese Einladung ist abgelaufen.',
            default => null,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
