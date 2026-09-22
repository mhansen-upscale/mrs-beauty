<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sie oder Du.
 *
 * Die folgenreichste einzelne Angabe im Brand Guide: eine Anzeige in der
 * falschen Ansprache wirkt nicht unpassend, sondern fremd.
 */
enum BrandAddress: string
{
    case Sie = 'sie';

    case Du = 'du';

    public function label(): string
    {
        return match ($this) {
            self::Sie => 'Sie',
            self::Du => 'Du',
        };
    }
}
