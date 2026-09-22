<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Warum ein Vergabelauf gestartet ist (docs/fachlogik/warteliste.md,
 * Abschnitt Ausloeser).
 *
 * **Der dritte ist der besondere.** Bei ausbleibender Reaktion auf die
 * Erinnerung gilt der Slot als wackelig und wird **parallel** angeboten --
 * ohne Hold, denn der Slot ist noch belegt, und ohne den bestehenden Termin
 * anzutasten.
 */
enum WaitlistTrigger: string
{
    case Cancellation = 'cancellation';

    case Reschedule = 'reschedule';

    /** Keine Reaktion auf die Erinnerung. Paralleles Angebot, kein Hold. */
    case NoResponse = 'no_response';

    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Cancellation => 'Absage',
            self::Reschedule => 'Verschiebung',
            self::NoResponse => 'Keine Reaktion',
            self::Manual => 'Freigabe durch das Team',
        };
    }

    /**
     * Wird fuer diesen Ausloeser ein Slot gehalten?
     *
     * Bei einem wackeligen Termin nicht: der Slot ist belegt, und ein Hold
     * darauf waere eine Reservierung fuer etwas, das noch jemandem gehoert.
     */
    public function haeltSlot(): bool
    {
        return $this !== self::NoResponse;
    }
}
