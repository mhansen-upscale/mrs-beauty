<?php

declare(strict_types=1);

namespace App\Enums;

enum ConversationStatus: string
{
    case Open = 'open';

    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Offen',
            self::Closed => 'Geschlossen',
        };
    }
}
