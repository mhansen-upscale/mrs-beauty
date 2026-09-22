<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\ChannelType;

/**
 * Der Weg vom Kanal zu seiner Umsetzung.
 *
 * Nach WP-19 ist die Zuordnung leer: die Strecke steht, die Kanaele kommen in
 * WP-20 und tragen sich hier ein. Wer vorher zu senden versucht, bekommt eine
 * deutliche Ausnahme statt eines stillen Fehlschlags.
 */
final class Kanalversender
{
    /** @var array<string, Kanalversand> */
    private array $versender = [];

    public function registriere(ChannelType $kanal, Kanalversand $versand): void
    {
        $this->versender[$kanal->value] = $versand;
    }

    public function fuer(ChannelType $kanal): Kanalversand
    {
        return $this->versender[$kanal->value] ?? throw KanalNichtAngebunden::fuer($kanal);
    }

    public function kennt(ChannelType $kanal): bool
    {
        return isset($this->versender[$kanal->value]);
    }
}
