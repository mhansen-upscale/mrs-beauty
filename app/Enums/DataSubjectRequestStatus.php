<?php

declare(strict_types=1);

namespace App\Enums;

enum DataSubjectRequestStatus: string
{
    case Open = 'open';

    case Completed = 'completed';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Offen',
            self::Completed => 'Erledigt',
            self::Rejected => 'Abgelehnt',
        };
    }
}
