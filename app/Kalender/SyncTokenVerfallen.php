<?php

declare(strict_types=1);

namespace App\Kalender;

use RuntimeException;

/**
 * Das Delta-Token gilt nicht mehr.
 *
 * **Kein Fehler, sondern ein vorgesehener Zustand.** Google antwortet mit
 * `410 Gone`, das Token wird verworfen und ein Vollabgleich laeuft an. Wer
 * das als Ausfall behandelt, hat einen Sync, der nach ein paar Wochen Ruhe
 * stillsteht -- docs/integrationen/kalender.md, "Fallstricke".
 */
final class SyncTokenVerfallen extends RuntimeException
{
    public static function machVollabgleich(): self
    {
        return new self('Das Sync-Token ist verfallen. Ein Vollabgleich ist noetig.');
    }
}
