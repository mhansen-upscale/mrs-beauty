<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wo ein Anzeigenentwurf steht.
 *
 * **Es gibt keinen Zustand, in dem einer ohne Menschen hinausgeht.**
 */
enum Vorschlagsstatus: string
{
    case Entwurf = 'draft';

    case Freigegeben = 'approved';

    case Verworfen = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Entwurf => 'Entwurf',
            self::Freigegeben => 'Freigegeben',
            self::Verworfen => 'Verworfen',
        };
    }
}
