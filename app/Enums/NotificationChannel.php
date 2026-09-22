<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ueber welchen Weg eine Terminnachricht geht.
 *
 * **Vorerst nur E-Mail.** WhatsApp und SMS waeren wirksamer und kosten Geld
 * je Nachricht (Entscheidungen B7 und B8). Vor der Kostenerfassung
 * Nachrichten zu verschicken, fuer die Meta abrechnet, ist der kuerzeste Weg
 * zu einer Abo-Marge, die niemand nachrechnen kann.
 *
 * Der Fall steht hier, damit WP-20 einen Fall ergaenzt und keine Tabelle.
 */
enum NotificationChannel: string
{
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-Mail',
        };
    }
}
