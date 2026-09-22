<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie eine Praxis klingt.
 *
 * Feste Liste, kein Freitext: "eher sachlich" ist eine Angabe, mit der ein
 * Vorschlag etwas anfangen kann. "modern, aber nicht zu modern" ist keine.
 */
enum BrandTone: string
{
    case Sachlich = 'sachlich';

    case Warm = 'warm';

    case Exklusiv = 'exklusiv';

    case Nahbar = 'nahbar';

    public function label(): string
    {
        return match ($this) {
            self::Sachlich => 'Sachlich und fachlich',
            self::Warm => 'Warm und zugewandt',
            self::Exklusiv => 'Exklusiv und hochwertig',
            self::Nahbar => 'Nahbar und unkompliziert',
        };
    }

    public function beschreibung(): string
    {
        return match ($this) {
            self::Sachlich => 'Nüchtern, erklärend, ohne Ausrufezeichen.',
            self::Warm => 'Persönlich, ermutigend, ohne Druck.',
            self::Exklusiv => 'Zurückhaltend, wertig, wenige Worte.',
            self::Nahbar => 'Alltagsnah, direkt, ohne Fachjargon.',
        };
    }
}
