<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Die Spur eines Termins im externen Kalender.
 *
 * Diese Zeile ist zugleich der Idempotenzschluessel (Entscheidung A13): liegt
 * eine externe Kennung vor, wird aktualisiert statt angelegt. Zwei Laeufe
 * desselben Auftrags erzeugen damit ein Event, nicht zwei -- und ein Job, der
 * zweimal laeuft, ist nach einem Deploy der Normalfall.
 *
 * @property string $calendar_connection_id
 * @property string $appointment_id
 * @property string|null $external_event_id
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $removed_at
 * @property-read CalendarConnection $connection
 * @property-read Appointment $appointment
 */
class CalendarEventLink extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['calendar_connection_id', 'appointment_id', 'external_event_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synced_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /** Steht das Event drueben? */
    public function stehtDraussen(): bool
    {
        return $this->external_event_id !== null && $this->removed_at === null;
    }

    /**
     * @return BelongsTo<CalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
