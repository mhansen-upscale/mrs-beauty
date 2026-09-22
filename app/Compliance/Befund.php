<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\Ampel;
use App\Enums\ComplianceCode;

/**
 * Ein einzelner Befund.
 *
 * **Mit Fundstelle und Formulierungsvorschlag.** Die Pruefung sagt nicht nur,
 * was nicht geht, sondern was stattdessen geht (docs/produkt.md) -- ein
 * Befund ohne Ausweg ist ein Vorwurf.
 */
final class Befund
{
    public function __construct(
        public readonly ComplianceCode $code,
        public readonly Ampel $ampel,
        public readonly ?string $stelle = null,
        public readonly ?string $vorschlag = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'titel' => $this->code->label(),
            'fundstelle' => $this->code->fundstelle(),
            'ampel' => $this->ampel->value,
            'stelle' => $this->stelle,
            'vorschlag' => $this->vorschlag,
        ];
    }
}
