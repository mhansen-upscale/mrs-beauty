<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was aus einem Angebot geworden ist.
 */
enum WaitlistOfferStatus: string
{
    /** Verschickt, Antwort steht aus. Haelt einen Slot. */
    case Pending = 'pending';

    case Accepted = 'accepted';

    case Declined = 'declined';

    case Expired = 'expired';

    /** Der Slot ist anderweitig vergeben worden. */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Offen',
            self::Accepted => 'Angenommen',
            self::Declined => 'Abgelehnt',
            self::Expired => 'Abgelaufen',
            self::Superseded => 'Überholt',
        };
    }

    public function istOffen(): bool
    {
        return $this === self::Pending;
    }
}
