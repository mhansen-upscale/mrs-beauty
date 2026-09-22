<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wer geschrieben hat.
 *
 * Die Richtung entscheidet ueber das Service-Fenster: es zaehlt ab der
 * letzten **eingehenden** Nachricht. Wer es beim Senden verlaengert, hat ein
 * Fenster, das nie zugeht -- und eine Rechnung, die nicht stimmt.
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';

    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Eingehend',
            self::Outbound => 'Ausgehend',
        };
    }
}
