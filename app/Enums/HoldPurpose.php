<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wozu ein Slot gehalten wird. Die Lebensdauer haengt daran
 * (config/mrs.php).
 */
enum HoldPurpose: string
{
    /** Buchungsdialog des Agenten, 15 Minuten (docs/fachlogik/agent.md). */
    case AgentDialog = 'agent_dialog';

    /** Wartelistenangebot, 30 Minuten (docs/fachlogik/warteliste.md). */
    case WaitlistOffer = 'waitlist_offer';

    /** Oeffentliche Buchungsseite, 10 Minuten (WP-12). */
    case PublicBooking = 'public_booking';

    /** Interne Terminverwaltung (WP-11). */
    case Internal = 'internal';

    public function ttlMinutes(): int
    {
        return match ($this) {
            self::AgentDialog => (int) config('mrs.agent.slot_hold_ttl_minutes', 15),
            self::WaitlistOffer => (int) config('mrs.waitlist.offer_ttl_minutes', 30),
            self::PublicBooking => (int) config('mrs.booking.hold_ttl_minutes', 10),
            self::Internal => (int) config('mrs.booking.internal_hold_ttl_minutes', 10),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AgentDialog => 'Buchungsdialog',
            self::WaitlistOffer => 'Wartelistenangebot',
            self::PublicBooking => 'Buchungsseite',
            self::Internal => 'Intern',
        };
    }
}
