<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\MessageCostCategory;
use App\Enums\MessageStatus;
use Carbon\CarbonImmutable;

/**
 * Was der Anbieter spaeter ueber eine gesendete Nachricht meldet.
 *
 * **Hier kommt die Kostenkategorie her, nicht aus der Sendeantwort.** Die
 * Antwort auf den Versand traegt bei WhatsApp nur die Kennung; `pricing`
 * steht in der Statusrueckmeldung, Minuten spaeter. Der Leitfaden verlangt
 * die Kategorie "aus der API-Antwort" -- das ist diese hier.
 *
 * Die Kategorie ist deshalb auch optional: eine Rueckmeldung ohne `pricing`
 * sagt nichts ueber Kosten, und `none` waere an dieser Stelle eine Schaetzung
 * mit der Aussage "kostenlos".
 */
final class Rueckmeldung
{
    public function __construct(
        public readonly string $externeId,
        public readonly MessageStatus $status,
        public readonly ?MessageCostCategory $kategorie = null,
        public readonly ?CarbonImmutable $zeitpunkt = null,
        public readonly ?string $kurzgrund = null,
    ) {}
}
