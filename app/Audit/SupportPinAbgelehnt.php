<?php

declare(strict_types=1);

namespace App\Audit;

use RuntimeException;

/**
 * Eine Einmal-PIN wurde nicht angenommen (WP-34b).
 *
 * **Eine Meldung fuer jeden Grund.** Keine offene PIN, eine falsche, eine
 * abgelaufene oder verbrannte: Wer die Antworten unterscheiden koennte,
 * koennte abfragen, ob eine Praxis gerade eine PIN offen hat. Und nie mit
 * der eingetippten PIN (C5).
 */
final class SupportPinAbgelehnt extends RuntimeException
{
    public static function falsch(): self
    {
        return new self('Die PIN stimmt nicht oder gilt nicht mehr. Die Praxis kann unter Team eine neue erzeugen.');
    }

    public static function gedrosselt(int $sekunden): self
    {
        $minuten = max(1, (int) ceil($sekunden / 60));

        return new self("Zu viele falsche PINs. Bitte in {$minuten} Minuten erneut versuchen.");
    }
}
