<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wozu eine Terminnachricht verschickt wird.
 *
 * Als VARCHAR plus PHP-Enum (Entscheidung A11) -- eine Erweiterung waere
 * sonst ein ALTER TABLE.
 */
enum NotificationKind: string
{
    /** Buchung ueber die oeffentliche Seite: angefragt, noch nicht bestaetigt. */
    case RequestReceived = 'request_received';

    case Confirmation = 'confirmation';

    case Reminder = 'reminder';

    case Rescheduled = 'rescheduled';

    case Cancellation = 'cancellation';

    public function label(): string
    {
        return match ($this) {
            self::RequestReceived => 'Eingangsbestätigung',
            self::Confirmation => 'Terminbestätigung',
            self::Reminder => 'Erinnerung',
            self::Rescheduled => 'Terminverschiebung',
            self::Cancellation => 'Absage',
        };
    }

    /** Geht sofort raus, oder wartet auf ihren Zeitpunkt? */
    public function istSofort(): bool
    {
        return $this !== self::Reminder;
    }
}
