<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wo eine Aenderung gerade steht.
 *
 * **Was die Praxis will und was bei Meta steht, sind zwei Dinge.** Bis WP-26
 * war die Tabelle ein Spiegel. Ab WP-27 kann eine Zeile eine Aenderung
 * tragen, die noch unterwegs ist -- oder eine, die abgelehnt wurde. Wer das
 * nicht unterscheidet, zeigt eine Budgeterhoehung an, die nie ankam.
 */
enum SyncState: string
{
    /** Steht so bei Meta. */
    case Synced = 'synced';

    /** Liegt lokal vor, ein Auftrag traegt es hinaus. */
    case Pending = 'pending';

    /** Meta hat abgelehnt. Der Grund steht im Klartext daneben. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Synced => 'Übertragen',
            self::Pending => 'Wird übertragen',
            self::Failed => 'Nicht übertragen',
        };
    }

    public function brauchtAufmerksamkeit(): bool
    {
        return $this === self::Failed;
    }
}
