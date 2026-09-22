<?php

declare(strict_types=1);

namespace App\Kontakte;

use RuntimeException;

/**
 * Diese beiden Kontakte lassen sich nicht zusammenfuehren.
 *
 * Abzugrenzen von "soll nicht": ein fehlendes hartes Signal ist kein Fehler,
 * sondern ein Vorschlag (Entscheidung D6). Hier geht es um Faelle, die gar
 * nicht erst zustande kommen duerfen.
 */
final class Nichtzusammenfuehrbar extends RuntimeException
{
    public static function derselbeKontakt(): self
    {
        return new self('Ein Kontakt laesst sich nicht mit sich selbst zusammenfuehren.');
    }

    public static function fremderMandant(): self
    {
        return new self('Kontakte zweier Organisationen lassen sich nicht zusammenfuehren.');
    }

    public static function nichtMehrUmkehrbar(): self
    {
        return new self('Diese Zusammenfuehrung laesst sich nicht mehr rueckgaengig machen.');
    }
}
