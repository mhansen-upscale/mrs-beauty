<?php

declare(strict_types=1);

namespace App\Kalender;

/**
 * Was beim Verbinden ueber den Kalender selbst zu erfahren ist.
 *
 * Kennung und Adresse sind personenbezogen -- bei beiden Anbietern ist das in
 * aller Regel eine E-Mail-Adresse -- und liegen verschluesselt (Regel 3).
 * Die Zone wird abgefragt und nicht angenommen: ganztaegige Events werden
 * darin ausgewertet.
 */
final class Kalenderangaben
{
    public function __construct(
        public readonly string $kennung,
        public readonly string $adresse,
        public readonly string $zone,
    ) {}
}
