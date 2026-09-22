<?php

declare(strict_types=1);

namespace App\Anzeigen;

/**
 * Eine Textvariante, wie sie aus dem Sprachmodell kommt.
 */
final class Entwurf
{
    public function __construct(
        public readonly string $ueberschrift,
        public readonly string $text,
        public readonly ?string $beschreibung = null,
        public readonly ?string $handlungsaufruf = null,
    ) {}

    /** Alles, was die HWG-Pruefung sehen muss. */
    public function gesamttext(): string
    {
        return trim(implode("\n", array_filter([
            $this->ueberschrift,
            $this->text,
            $this->beschreibung,
            $this->handlungsaufruf,
        ])));
    }
}
