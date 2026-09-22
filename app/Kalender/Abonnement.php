<?php

declare(strict_types=1);

namespace App\Kalender;

use Carbon\CarbonImmutable;

/**
 * Ein bestelltes Abonnement.
 *
 * Die Kennung kommt bei Graph vom Anbieter, bei Google vergeben wir sie
 * selbst -- nach aussen ist beides dasselbe: etwas, das die Zustellung
 * mitbringt und ueber das sich das Abonnement wieder beenden laesst.
 */
final class Abonnement
{
    public function __construct(
        public readonly string $kennung,
        public readonly string $ressource,
        public readonly CarbonImmutable $laeuftAb,
    ) {}
}
