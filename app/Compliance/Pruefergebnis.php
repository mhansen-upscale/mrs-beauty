<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\Ampel;
use Carbon\CarbonImmutable;

/**
 * Was eine Pruefung ergeben hat -- samt Rechtsstand und Fassung.
 *
 * Beides gehoert an jedes Ergebnis, sonst laesst sich spaeter nicht sagen,
 * warum etwas damals durchging.
 */
final class Pruefergebnis
{
    /**
     * @param  list<Befund>  $befunde
     */
    public function __construct(
        public readonly Ampel $ampel,
        public readonly array $befunde,
        public readonly int $version,
        public readonly CarbonImmutable $rechtsstand,
        public readonly CarbonImmutable $geprueftAm,
        public readonly bool $regelwerkGeprueft,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ampel' => $this->ampel->value,
            'ampelText' => $this->ampel->label(),
            'befunde' => array_map(fn (Befund $b): array => $b->toArray(), $this->befunde),
            'version' => $this->version,
            'rechtsstand' => $this->rechtsstand->toDateString(),
            'geprueftAm' => $this->geprueftAm->toIso8601String(),
            'regelwerkGeprueft' => $this->regelwerkGeprueft,
        ];
    }
}
