<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die Statusliste ist durch die Kennzahlendefinitionen in
 * docs/fachlogik/attribution.md festgelegt und **nicht frei erweiterbar**:
 * "gebucht" zaehlt pending, confirmed und attended, "erschienen" nur attended,
 * die No-Show-Quote rechnet no_show gegen erschienen.
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Attended = 'attended';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Angefragt',
            self::Confirmed => 'Bestätigt',
            self::Attended => 'Erschienen',
            self::NoShow => 'Nicht erschienen',
            self::Cancelled => 'Abgesagt',
        };
    }

    /** Zaehlt als gebucht (docs/fachlogik/attribution.md, Kennzahlen). */
    public function giltAlsGebucht(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Attended], true);
    }

    /** Belegt der Termin noch Slots? */
    public function belegt(): bool
    {
        return $this !== self::Cancelled;
    }
}
