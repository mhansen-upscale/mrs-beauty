<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Nachricht zu einem Termin -- geplant, verschickt oder fehlgeschlagen.
 *
 * **Bewusst ohne Auditable.** Ein Protokolleintrag je verschickter Erinnerung
 * waere ein Protokoll, in dem man nichts mehr findet; diese Tabelle ist
 * selbst der Nachweis, wer wann was bekommen hat.
 *
 * @property string $appointment_id
 * @property NotificationKind $kind
 * @property NotificationChannel $channel
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure
 * @property-read Appointment $appointment
 */
class AppointmentNotification extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['appointment_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => NotificationKind::class,
            'channel' => NotificationChannel::class,
            'scheduled_for' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function istOffen(): bool
    {
        return $this->sent_at === null && $this->failed_at === null;
    }

    /**
     * Faellige, noch nicht verschickte Nachrichten.
     *
     * @param  Builder<AppointmentNotification>  $query
     * @return Builder<AppointmentNotification>
     */
    public function scopeFaellig(Builder $query, ?CarbonImmutable $jetzt = null): Builder
    {
        return $query
            ->whereNull('sent_at')
            ->whereNull('failed_at')
            ->where('scheduled_for', '<=', $jetzt ?? CarbonImmutable::now());
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
