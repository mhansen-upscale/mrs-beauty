<?php

declare(strict_types=1);

namespace App\Whitelabel;

/**
 * Was bei der Pruefung einer Markenfarbe herauskam.
 *
 * Drei Ausgaenge, und sie verhalten sich verschieden (docs/design/farben.md):
 * abgelehnt, uebernommen mit Hinweis, uebernommen mit Warnung.
 */
final class Farbbefund
{
    /**
     * @param  list<string>  $hinweise
     */
    public function __construct(
        public readonly bool $angenommen,
        public readonly ?string $farbe = null,
        public readonly ?string $ablehnung = null,
        public readonly array $hinweise = [],
    ) {}

    public static function abgelehnt(string $grund): self
    {
        return new self(angenommen: false, ablehnung: $grund);
    }

    /**
     * @param  list<string>  $hinweise
     */
    public static function angenommen(string $farbe, array $hinweise = []): self
    {
        return new self(angenommen: true, farbe: $farbe, hinweise: $hinweise);
    }
}
