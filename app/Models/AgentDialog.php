<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Enums\BookingState;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Der Buchungsvorgang einer Konversation (docs/fachlogik/agent.md, Schritt 6).
 *
 * **Einer je Konversation** -- der Unique-Index ist der Vorgangsschluessel
 * aus Entscheidung G9. Doppelbuchung aus dem Chat ist der Vertrauenskiller.
 *
 * @property BookingState $state
 * @property int $attempts
 * @property string $conversation_id
 * @property string|null $treatment_id
 * @property string|null $appointment_type_id
 * @property string|null $location_id
 * @property string|null $practitioner_id
 * @property string|null $slot_hold_id
 * @property string|null $appointment_id
 * @property string|null $name
 * @property string|null $offered
 * @property CarbonImmutable|null $consent_at
 */
class AgentDialog extends TenantModel implements HasPersonalData
{
    use MasksPersonalData;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'conversation_id',
        'treatment_id',
        'appointment_type_id',
        'location_id',
        'practitioner_id',
        'slot_hold_id',
        'appointment_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => BookingState::class,
            'attempts' => 'integer',
            'name' => Encrypted::class,
            'offered' => Encrypted::class,
            'consent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['name', 'offered'];
    }

    /**
     * Die angebotenen Zeitpunkte, in der Reihenfolge des Angebots.
     *
     * @return list<string>
     */
    public function angebote(): array
    {
        if (! is_string($this->offered) || $this->offered === '') {
            return [];
        }

        $werte = json_decode($this->offered, true);

        return is_array($werte) ? array_values(array_map(strval(...), $werte)) : [];
    }

    /**
     * @param  list<string>  $zeitpunkte
     */
    public function setzeAngebote(array $zeitpunkte): void
    {
        $this->offered = $zeitpunkte === [] ? null : (string) json_encode($zeitpunkte);
    }

    /**
     * Wechselt den Zustand -- und setzt den Zaehler zurueck.
     *
     * Drei Klaerungsversuche gelten **je Zustand**: wer die Behandlung
     * geklaert hat, faengt beim Standort wieder bei null an.
     */
    public function wechsleNach(BookingState $zustand): void
    {
        $this->state = $zustand;
        $this->attempts = 0;
        $this->save();
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<SlotHold, $this>
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(SlotHold::class, 'slot_hold_id');
    }
}
