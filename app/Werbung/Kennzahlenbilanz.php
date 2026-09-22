<?php

declare(strict_types=1);

namespace App\Werbung;

use Carbon\CarbonImmutable;

/**
 * Was ein Kennzahlenabgleich geholt hat. Steht im Befehl.
 */
final class Kennzahlenbilanz
{
    public function __construct(
        public readonly int $zeilen,
        public readonly int $tage,
        public readonly CarbonImmutable $von,
        public readonly CarbonImmutable $bis,
    ) {}
}
