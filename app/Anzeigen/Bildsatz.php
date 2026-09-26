<?php

declare(strict_types=1);

namespace App\Anzeigen;

/**
 * Was ein Bildauftrag ueber alle Formate ergab (WP-31b).
 *
 * **Die Bilder, die ankamen, und die Gruende fuer die anderen** -- nebeneinander,
 * statt dass ein gescheitertes Format die gelungenen mitreisst. Jedes Format
 * ist bezahlt, sobald es beim Anbieter liegt.
 *
 * Schluessel sind die Werte von `Bildformat`, in der Reihenfolge des
 * Auftrags.
 */
final class Bildsatz
{
    /**
     * @param  array<string, Bild>  $bilder
     * @param  array<string, string>  $fehler
     */
    public function __construct(
        public readonly array $bilder,
        public readonly array $fehler = [],
    ) {}
}
