<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie weit eine Nachricht gekommen ist.
 *
 * Eingehende Nachrichten sind sofort `delivered` -- sie sind ja da. Die
 * Kette gilt dem Versand: eingereiht, gesendet, zugestellt, gelesen. Oder
 * gescheitert, mit Grund.
 */
enum MessageStatus: string
{
    case Queued = 'queued';

    case Sent = 'sent';

    case Delivered = 'delivered';

    case Read = 'read';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Eingereiht',
            self::Sent => 'Gesendet',
            self::Delivered => 'Zugestellt',
            self::Read => 'Gelesen',
            self::Failed => 'Fehlgeschlagen',
        };
    }

    public function istEndzustand(): bool
    {
        return $this === self::Read || $this === self::Failed;
    }
}
