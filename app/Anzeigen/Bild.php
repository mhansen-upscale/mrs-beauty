<?php

declare(strict_types=1);

namespace App\Anzeigen;

/**
 * Ein erzeugtes Bild -- schon heruntergeladen.
 *
 * **Die Bytes, nicht die Adresse** (Entscheidung C10). Eine fremde
 * CDN-Adresse, die irgendwann 404 liefert, waere der Beleg, den es nicht mehr
 * gibt.
 */
final class Bild
{
    public function __construct(
        public readonly string $inhalt,
        public readonly string $mime,
        public readonly string $modell,
    ) {}
}
