<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Vorlagen;

/**
 * Der Kern einer Mail, den keine Vorlage aendert (Entscheidung C17).
 *
 * Eckdaten des Termins, Schaltflaeche mit Link, Frist, Code, der Satz im
 * Alarm. Er steht zwischen Einleitung und Schluss -- die Vorlage schreibt
 * davor und danach, nie hinein.
 */
final class Festblock
{
    /**
     * @param  list<string>  $vorher  Markdown, fertig escaped
     * @param  list<string>  $nachher  Markdown, fertig escaped
     */
    public function __construct(
        public readonly array $vorher = [],
        public readonly ?string $schaltflaeche = null,
        public readonly ?string $ziel = null,
        public readonly array $nachher = [],
    ) {}
}
