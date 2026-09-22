<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Enums\RetentionSubject;

/**
 * Was ein Aufbewahrungslauf getan hat -- oder getan haette.
 *
 * Dieselbe Form fuer Vorschau und Ernstfall. Wer beides verschieden
 * darstellt, vergleicht hinterher Aepfel mit Birnen und traut der Vorschau
 * nicht mehr.
 */
final class Aufbewahrungsergebnis
{
    /** @var array<string, int> */
    private array $zeilen = [];

    public function __construct(public readonly bool $vorschau) {}

    public function zaehle(RetentionSubject $gegenstand, int $anzahl): void
    {
        $this->zeilen[$gegenstand->value] = ($this->zeilen[$gegenstand->value] ?? 0) + $anzahl;
    }

    /**
     * @return array<string, int>
     */
    public function nachGegenstand(): array
    {
        return $this->zeilen;
    }

    public function gesamt(): int
    {
        return array_sum($this->zeilen);
    }
}
