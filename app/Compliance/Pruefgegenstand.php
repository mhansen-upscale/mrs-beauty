<?php

declare(strict_types=1);

namespace App\Compliance;

use Illuminate\Database\Eloquent\Model;

/**
 * Was geprueft wird.
 *
 * Ein Traeger statt eines Modells, weil dieselbe Pruefung
 * Anzeigenvorschlaege, Creatives, Behandlungsbeschreibungen, Templates und
 * die Buchungsseite trifft (docs/datenmodell.md, Abschnitt 9). Was sie
 * gemeinsam haben, ist Text und manchmal ein Bild -- mehr braucht die
 * Pruefung nicht.
 */
final class Pruefgegenstand
{
    /**
     * @param  list<string>  $bildnamen  Dateinamen oder Bildtitel
     */
    public function __construct(
        public readonly string $text,
        public readonly array $bildnamen = [],
        public readonly bool $hatBild = false,
        public readonly ?Model $modell = null,
    ) {}

    /** Alles, was an Text zu pruefen ist -- einschliesslich der Bildtitel. */
    public function gesamttext(): string
    {
        return trim($this->text.' '.implode(' ', $this->bildnamen));
    }
}
