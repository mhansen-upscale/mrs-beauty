<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bevorzugt oder verboten.
 *
 * Die verbotenen speisen zwei Pruefungen: den Markenhinweis hier und
 * `brand_violation` im HWG-Regelwerk (WP-30). Deshalb traegt jeder Begriff
 * einen Ersatz und eine Begruendung -- "nicht 'schmerzfrei' schreiben" ist
 * eine Anweisung, "statt 'schmerzfrei' lieber 'gut vertraeglich', weil
 * Paragraf 3 HWG" ist eine, der jemand folgen kann.
 */
enum BrandTermKind: string
{
    case Bevorzugt = 'preferred';

    case Verboten = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Bevorzugt => 'Bevorzugt',
            self::Verboten => 'Vermeiden',
        };
    }
}
