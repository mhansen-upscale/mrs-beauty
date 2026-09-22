<?php

declare(strict_types=1);

namespace App\Kalender;

/**
 * Was ein Rueckabgleich getan hat.
 *
 * `uebersprungen` zaehlt die eigenmarkierten Events (R1). Die Zahl ist kein
 * Beiwerk: steht sie bei null, obwohl Termine geschrieben wurden, stimmt
 * etwas mit der Markierung nicht -- und das ist der Fehler, der eine
 * Endlosschleife erzeugt.
 */
final class Abgleichergebnis
{
    public function __construct(
        public readonly int $uebernommen = 0,
        public readonly int $entfernt = 0,
        public readonly int $uebersprungen = 0,
        public readonly int $belegteSlots = 0,
        public readonly bool $voll = false,
        public readonly bool $unterbrochen = false,
    ) {}

    public static function unterbrochen(): self
    {
        return new self(unterbrochen: true);
    }
}
