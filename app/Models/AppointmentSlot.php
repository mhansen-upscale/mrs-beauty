<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein 5-Minuten-Schritt eines Behandlers (Entscheidung A9).
 *
 * MySQL kennt keine Exclusion Constraints, mit denen sich ueberschneidende
 * Zeitraeume auf Datenbankebene ausschliessen liessen. Die Materialisierung
 * ist der Ersatz: der Unique-Index (practitioner_id, starts_at) macht
 * Doppelvergabe zu einem Datenbankfehler.
 *
 * **Bewusst ohne Auditable.** Der Erzeugungsjob legt zehntausende Zeilen an;
 * jede davon zu protokollieren waere ein Protokoll, in dem man nichts mehr
 * findet. Protokolliert werden Termine und Holds.
 *
 * @property CarbonImmutable $starts_at
 * @property string $practitioner_id
 * @property string $location_id
 * @property string|null $appointment_id
 * @property string|null $slot_hold_id
 * @property string|null $external_block_id
 */
class AppointmentSlot extends TenantModel
{
    /**
     * Keine Zeitstempel.
     *
     * Das ist die eine Tabelle, die in die Millionen geht: 90 Tage mal acht
     * Stunden mal zwoelf Schritte je Behandler. created_at und updated_at
     * waeren 16 Byte je Zeile fuer eine Information, die niemand abfragt --
     * wann ein Slot materialisiert wurde, sagt nichts.
     */
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'practitioner_id',
        'location_id',
        'appointment_id',
        'slot_hold_id',
        'external_block_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
        ];
    }

    /**
     * Freie Slots.
     *
     * Ein Slot ist frei, wenn kein Termin und kein externer Blocker darauf
     * liegt **und** kein Hold, der noch gilt. Der abgelaufene Hold ist damit
     * sofort wirkungslos, ohne dass ihn jemand aufraeumen muesste.
     *
     * @param  Builder<AppointmentSlot>  $query
     * @return Builder<AppointmentSlot>
     */
    public function scopeFrei(Builder $query): Builder
    {
        return $query
            ->whereNull('appointment_id')
            ->whereNull('external_block_id')
            ->where(function (Builder $abfrage): void {
                $abfrage->whereNull('slot_hold_id')
                    ->orWhereExists(function ($unterabfrage): void {
                        $unterabfrage->selectRaw('1')
                            ->from('slot_holds')
                            ->whereColumn('slot_holds.id', 'appointment_slots.slot_hold_id')
                            ->where(function ($h): void {
                                $h->whereNotNull('slot_holds.released_at')
                                    ->orWhere('slot_holds.expires_at', '<=', now());
                            });
                    });
            });
    }

    /**
     * Frei aus Sicht **dieses** Termins.
     *
     * Beim Verschieben ist die eigene bisherige Belegung kein Hindernis: wer
     * einen Termin um zehn Minuten schiebt, ueberlappt sich fast vollstaendig
     * selbst. Fuer jeden anderen Termin ist dieselbe Zeile belegt.
     */
    public function istFreiFuer(?Appointment $termin): bool
    {
        if ($termin instanceof Appointment
            && $this->appointment_id !== null
            && $this->appointment_id === $termin->getKey()) {
            return true;
        }

        return $this->istFrei();
    }

    public function istFrei(): bool
    {
        if ($this->appointment_id !== null || $this->external_block_id !== null) {
            return false;
        }

        if ($this->slot_hold_id === null) {
            return true;
        }

        // Nicht ueber die Beziehung: strenge Modelle lassen kein Nachladen zu,
        // und diese Methode laeuft in Schleifen ueber gesperrte Zeilen. Wer
        // die Beziehung vorher laedt, spart die Abfrage.
        $hold = $this->relationLoaded('hold')
            ? $this->getRelation('hold')
            : SlotHold::query()->whereKey($this->slot_hold_id)->first();

        return ! $hold instanceof SlotHold || ! $hold->giltNoch();
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<SlotHold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(SlotHold::class, 'slot_hold_id');
    }

    /**
     * @return BelongsTo<Practitioner, $this>
     */
    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
