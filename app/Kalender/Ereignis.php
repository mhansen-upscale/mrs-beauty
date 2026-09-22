<?php

declare(strict_types=1);

namespace App\Kalender;

use Carbon\CarbonImmutable;

/**
 * Ein Event aus einem externen Kalender -- auf das reduziert, was uebernommen
 * werden darf.
 *
 * **Der Originaltitel hat hier keine Eigenschaft** (R2). Das ist die Stelle,
 * an der Datensparsamkeit durchgesetzt wird: was es hier nicht gibt, kann
 * weiter innen niemand speichern.
 *
 * Gemeinsam fuer beide Anbieter -- **nach** WP-15, nicht vorher. Gemeinsam ist
 * das Ergebnis; das Lesen bleibt getrennt, weil Google und Graph die Zeit
 * grundverschieden angeben (Ereignisleser je Anbieter).
 */
final class Ereignis
{
    public function __construct(
        public readonly string $externeId,
        public readonly bool $abgesagt,
        public readonly bool $eigen,
        public readonly bool $frei,
        public readonly bool $ganztaegig,
        public readonly ?CarbonImmutable $beginn,
        public readonly ?CarbonImmutable $ende,
    ) {}

    /** Ein Event, das eine Zeit belegt: nicht abgesagt, nicht frei, mit Zeiten. */
    public function istBlocker(): bool
    {
        return ! $this->abgesagt
            && ! $this->frei
            && ! $this->eigen
            && $this->beginn instanceof CarbonImmutable
            && $this->ende instanceof CarbonImmutable
            && $this->ende->greaterThan($this->beginn);
    }

    /** Dasselbe Ereignis, als eigenes erkannt. */
    public function alsEigenes(): self
    {
        return new self(
            externeId: $this->externeId,
            abgesagt: $this->abgesagt,
            eigen: true,
            frei: $this->frei,
            ganztaegig: $this->ganztaegig,
            beginn: $this->beginn,
            ende: $this->ende,
        );
    }
}
