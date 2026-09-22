<?php

declare(strict_types=1);

namespace App\Werbung;

/**
 * Was ein Abgleich bewegt hat. Steht in der Oberflaeche und im Befehl.
 */
final class Abgleichbilanz
{
    public function __construct(
        public readonly int $angelegt = 0,
        public readonly int $geaendert = 0,
        public readonly int $verschwunden = 0,
    ) {}

    public function plus(int $angelegt, int $geaendert, int $verschwunden): self
    {
        return new self(
            $this->angelegt + $angelegt,
            $this->geaendert + $geaendert,
            $this->verschwunden + $verschwunden,
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'angelegt' => $this->angelegt,
            'geaendert' => $this->geaendert,
            'verschwunden' => $this->verschwunden,
        ];
    }
}
