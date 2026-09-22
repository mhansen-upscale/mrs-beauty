<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Enums\InsightLevel;
use Carbon\CarbonImmutable;

/**
 * Eine Zeile aus Metas Tagesreihe.
 *
 * Vier Grundwerte und die Leadzahl. Quoten stehen hier nicht: sie werden
 * gerechnet, nicht uebertragen.
 */
final class Tageszahl
{
    public function __construct(
        public readonly InsightLevel $ebene,
        public readonly string $kennung,
        public readonly CarbonImmutable $tag,
        public readonly int $ausgabenMinor,
        public readonly int $impressionen,
        public readonly int $klicks,
        public readonly int $linkklicks,
        public readonly int $leads,
    ) {}
}
