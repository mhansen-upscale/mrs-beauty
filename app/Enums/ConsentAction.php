<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Erteilt oder widerrufen.
 *
 * Einwilligungen werden **nicht ueberschrieben**, sondern fortgeschrieben:
 * jede Aenderung ist ein eigener Eintrag mit Zeitpunkt und Textstand. Nur so
 * laesst sich hinterher belegen, wem wann wozu zugestimmt wurde -- und die
 * Beweislast liegt beim Verantwortlichen.
 */
enum ConsentAction: string
{
    case Granted = 'granted';

    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Granted => 'Erteilt',
            self::Revoked => 'Widerrufen',
        };
    }
}
