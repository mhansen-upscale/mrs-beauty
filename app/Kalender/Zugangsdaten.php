<?php

declare(strict_types=1);

namespace App\Kalender;

use Carbon\CarbonImmutable;

/**
 * Was beim Tausch eines Autorisierungscodes herauskommt.
 *
 * Der Aktualisierungsschluessel kann fehlen, und beide Anbieter meinen damit
 * Verschiedenes: Google schickt ihn nur beim ersten Mal, Microsoft dreht ihn
 * bei jeder Erneuerung. Beides endet an derselben Stelle -- wer ihn nicht
 * mitschreibt, hat eine Verbindung, die genau einmal funktioniert.
 */
final class Zugangsdaten
{
    public function __construct(
        public readonly string $zugang,
        public readonly ?string $aktualisierung,
        public readonly CarbonImmutable $laeuftAb,
    ) {}
}
