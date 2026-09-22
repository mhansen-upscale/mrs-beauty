<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Wochentag nach ISO 8601: 1 = Montag, 7 = Sonntag.
 *
 * Die Nummerierung ist nicht beliebig. Die Wochentagsmaske der Warteliste
 * (Bedingung K6 in docs/fachlogik/warteliste.md) muss sich spaeter auf
 * dieselbe Zaehlung beziehen -- und Carbon liefert mit dayOfWeekIso genau
 * diese.
 */
enum Weekday: int
{
    case Montag = 1;
    case Dienstag = 2;
    case Mittwoch = 3;
    case Donnerstag = 4;
    case Freitag = 5;
    case Samstag = 6;
    case Sonntag = 7;

    public function label(): string
    {
        return $this->name;
    }

    public function kurz(): string
    {
        return mb_substr($this->name, 0, 2);
    }

    public static function fromDate(CarbonInterface $zeitpunkt): self
    {
        return self::from($zeitpunkt->dayOfWeekIso);
    }

    /** Das Bit dieses Tages in der Wochentagsmaske der Warteliste. */
    public function bit(): int
    {
        return 1 << ($this->value - 1);
    }
}
