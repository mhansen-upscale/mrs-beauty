<?php

declare(strict_types=1);

namespace App\Kalender;

use RuntimeException;

/**
 * Der Zugang zum Kalender besteht nicht mehr.
 *
 * Ein Refresh-Token laeuft ab oder wird im Google-Konto entzogen; die Antwort
 * ist dann `invalid_grant`. R4: die Verbindung wechselt auf `expired` und im
 * Produkt erscheint eine Aufforderung zur Neuverbindung. Ein stiller Ausfall
 * bedeutet, dass Termine ueber belegten Zeiten gebucht werden.
 */
final class ZugangEntzogen extends RuntimeException
{
    public static function neuVerbinden(): self
    {
        return new self('Der Zugang zum Kalender wurde entzogen. Die Verbindung muss neu hergestellt werden.');
    }
}
