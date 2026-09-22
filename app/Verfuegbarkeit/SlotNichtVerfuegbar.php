<?php

declare(strict_types=1);

namespace App\Verfuegbarkeit;

use RuntimeException;

/**
 * Der gewuenschte Zeitraum liess sich nicht halten.
 *
 * Die Meldung ist bewusst verstaendlich: sie wird der Interessentin gezeigt,
 * wenn sie einen Vorschlag annimmt, der inzwischen vergeben ist
 * (docs/fachlogik/warteliste.md, Grenzfaelle).
 */
final class SlotNichtVerfuegbar extends RuntimeException
{
    public static function vergeben(): self
    {
        return new self('Dieser Termin ist inzwischen vergeben.');
    }

    public static function unvollstaendig(int $erwartet, int $gefunden): self
    {
        return new self(
            "Der Zeitraum ist nicht durchgehend verfügbar: {$gefunden} von "
            ."{$erwartet} Abschnitten vorhanden."
        );
    }
}
