<?php

declare(strict_types=1);

namespace App\Enums;

/** Warum ein Behandler nicht da ist (Bedingung V2 der Verfuegbarkeit). */
enum AbsenceReason: string
{
    case Vacation = 'vacation';
    case Sick = 'sick';
    case Training = 'training';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Urlaub',
            self::Sick => 'Krankheit',
            self::Training => 'Fortbildung',
            self::Other => 'Sonstiges',
        };
    }
}
