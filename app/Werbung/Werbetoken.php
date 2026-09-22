<?php

declare(strict_types=1);

namespace App\Werbung;

use Carbon\CarbonImmutable;

/**
 * Was aus der Login-Strecke zurueckkommt.
 *
 * Ohne Ablaufzeitpunkt gaebe es keine Ablaufueberwachung, und die verlangt
 * `docs/integrationen/meta.md` ausdruecklich.
 */
final class Werbetoken
{
    public function __construct(
        public readonly string $zugang,
        public readonly ?CarbonImmutable $laeuftAb,
    ) {}
}
