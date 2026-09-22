<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was eine betroffene Person verlangt.
 *
 * Artikel 15, 16 und 17 DSGVO. Die Fristen sind gesetzlich (ein Monat), die
 * Umsetzung ist es hier: ohne Werkzeug wird aus einer Auskunft eine
 * Handarbeit ueber zwoelf Tabellen, und dabei wird etwas vergessen.
 */
enum DataSubjectRequestType: string
{
    case Access = 'access';

    case Rectification = 'rectification';

    case Deletion = 'deletion';

    public function label(): string
    {
        return match ($this) {
            self::Access => 'Auskunft',
            self::Rectification => 'Berichtigung',
            self::Deletion => 'Löschung',
        };
    }
}
