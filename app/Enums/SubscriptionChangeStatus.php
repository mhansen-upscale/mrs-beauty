<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Stand eines Eingriffs (WP-34c).
 *
 * **Erledigt heisst: Stripe hat den Auftrag angenommen.** Was daraus fuer das
 * Abo folgt, meldet der Webhook -- das Backoffice zeigt bis dahin
 * "beauftragt", nicht den erhofften Zustand.
 */
enum SubscriptionChangeStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Beauftragt',
            self::Done => 'Erledigt',
            self::Failed => 'Gescheitert',
        };
    }
}
