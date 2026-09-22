<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\ChannelType;
use RuntimeException;

/**
 * Fuer diesen Kanal gibt es noch keine Umsetzung.
 *
 * Der ehrliche Zustand nach WP-19: die Strecke steht, die vier Kanaele
 * kommen in WP-20. Ein stiller Fehlschlag waere hier schlimmer als eine
 * deutliche Ausnahme -- eine Nachricht, die niemand abschickt und die
 * trotzdem als gesendet gilt, faellt erst auf, wenn niemand antwortet.
 */
final class KanalNichtAngebunden extends RuntimeException
{
    public static function fuer(ChannelType $kanal): self
    {
        return new self("Fuer den Kanal {$kanal->label()} ist noch kein Versand angebunden (WP-20).");
    }
}
