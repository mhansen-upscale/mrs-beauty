<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\ChannelType;

/**
 * Der Weg vom Kanal zu seinem Leser.
 *
 * Nach WP-19 leer: die Strecke steht, die vier Kanaele tragen sich in WP-20
 * ein. Ein unbekannter Kanal fuehrt nicht zu einem Fehlschlag -- das
 * Rohereignis bleibt liegen und laesst sich erneut einspielen, sobald es
 * einen Leser gibt. Genau dafuer gibt es die Tabelle.
 */
final class Kanaleingaenge
{
    /** @var array<string, Kanaleingang> */
    private array $leser = [];

    public function registriere(ChannelType $kanal, Kanaleingang $eingang): void
    {
        $this->leser[$kanal->value] = $eingang;
    }

    public function fuer(ChannelType $kanal): ?Kanaleingang
    {
        return $this->leser[$kanal->value] ?? null;
    }
}
