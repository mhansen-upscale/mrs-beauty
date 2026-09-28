<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eine Einmal-PIN, mit der die Inhaberin den Vollzugriff freigibt (WP-34b).
 *
 * **Gespeichert ist nur der Hash.** Sechs Stellen sind schwach; das ist in
 * Ordnung, solange die PIN kurz lebt, nach fuenf Fehlversuchen verbrennt und
 * ein Betreiber nicht ueber viele Praxen hinweg durchprobieren kann (C15).
 *
 * @property string $created_by_user_id
 * @property string $pin_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property string|null $used_by_user_id
 * @property string|null $impersonation_session_id
 * @property int $failed_attempts
 * @property CarbonImmutable|null $revoked_at
 */
class SupportPin extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'pin_hash',
        'open_guard',
        'created_by_user_id',
        'used_by_user_id',
        'impersonation_session_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'failed_attempts' => 'integer',
        ];
    }

    /**
     * Weder eingeloest noch widerrufen -- **auch wenn abgelaufen**. Das ist
     * genau der Zustand, den `open_guard` zaehlt.
     *
     * @param  Builder<SupportPin>  $query
     * @return Builder<SupportPin>
     */
    public function scopeOffen(Builder $query): Builder
    {
        return $query->whereNull('used_at')->whereNull('revoked_at');
    }

    public function istGueltig(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
