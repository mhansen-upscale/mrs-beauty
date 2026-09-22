<?php

declare(strict_types=1);

namespace App\Marke;

/**
 * Ein verbotener Begriff, der in einem Text steht.
 */
final class Begriffstreffer
{
    public function __construct(
        public readonly string $begriff,
        public readonly ?string $ersatz,
        public readonly ?string $begruendung,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'begriff' => $this->begriff,
            'ersatz' => $this->ersatz,
            'begruendung' => $this->begruendung,
        ];
    }
}
