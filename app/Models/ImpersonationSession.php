<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImpersonationMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Impersonation durch einen Super-Admin (Entscheidung C4).
 *
 * @property ImpersonationMode $mode
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $ended_at
 * @property string $reason
 */
class ImpersonationSession extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'impersonator_user_id',
        'impersonated_user_id',
        'approved_by_user_id',
        'running_guard',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ImpersonationMode::class,
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * Laeuft die Sitzung gerade?
     *
     * Der Vergleich gegen die Uhr ist Absicht: eine abgelaufene Sitzung ist
     * **sofort** wirkungslos, nicht erst wenn ein Aufraeumjob sie anfasst.
     */
    public function laeuft(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function istAbgelaufen(): bool
    {
        return $this->ended_at === null && $this->expires_at->isPast();
    }

    public function hatVollzugriff(): bool
    {
        return $this->laeuft()
            && $this->mode === ImpersonationMode::Full
            && $this->approved_at !== null;
    }

    /**
     * @param  Builder<ImpersonationSession>  $query
     * @return Builder<ImpersonationSession>
     */
    public function scopeLaufend(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
