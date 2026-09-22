<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine belegte Zeit aus einem externen Kalender.
 *
 * **Ohne Titel, und das ist der Punkt.** R2 aus
 * docs/integrationen/kalender.md: eingehend wird ausschliesslich der Zeitraum
 * uebernommen, der Originaltitel landet nirgends in der Datenbank. Die Tabelle
 * hat deshalb keine Spalte dafuer -- was es nicht gibt, kann niemand spaeter
 * "nur zur Anzeige" befuellen.
 *
 * **Bewusst ohne Auditable.** Ein Kalender mit vierzig Terminen je Woche
 * erzeugte ein Protokoll, in dem man nichts mehr findet. Protokolliert wird
 * die Verbindung, nicht jedes Event darin.
 *
 * @property string $calendar_connection_id
 * @property string $practitioner_id
 * @property string $external_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property bool $is_all_day
 */
class ExternalCalendarBlock extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['calendar_connection_id', 'practitioner_id', 'external_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_all_day' => 'boolean',
        ];
    }

    /**
     * Blocker, die einen Zeitraum beruehren.
     *
     * Beruehren heisst ueberlappen, nicht enthalten sein: ein Event von 08:00
     * bis 12:00 blockiert auch eine Abfrage ueber 09:00 bis 10:00.
     *
     * @param  Builder<ExternalCalendarBlock>  $query
     * @return Builder<ExternalCalendarBlock>
     */
    public function scopeImZeitraum(Builder $query, CarbonImmutable $von, CarbonImmutable $bis): Builder
    {
        return $query->where('starts_at', '<', $bis)->where('ends_at', '>', $von);
    }

    /**
     * @return BelongsTo<CalendarConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }
}
