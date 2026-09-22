<?php

declare(strict_types=1);

namespace App\Enums;

/** Warum ein Standort geschlossen ist (Bedingung V3 der Verfuegbarkeit). */
enum ClosureReason: string
{
    case Holiday = 'holiday';
    case CompanyHoliday = 'company_holiday';
    case Renovation = 'renovation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Holiday => 'Feiertag',
            self::CompanyHoliday => 'Betriebsferien',
            self::Renovation => 'Umbau',
            self::Other => 'Sonstiges',
        };
    }
}
