<?php

declare(strict_types=1);

namespace App\Agent;

use App\Abrechnung\Kontingente;
use App\Abrechnung\Nutzungsuebersicht;
use Carbon\CarbonImmutable;

/**
 * Wie viel der Assistent in diesem Monat noch darf.
 *
 * **Seit WP-06 ist das eine Sicht auf das Abo und keine eigene Buchhaltung.**
 * Bis dahin fuehrte dieses Paket eine eigene Tabelle (`agent_budgets`) in
 * Zehntel-Cent, waehrend WP-06 dieselbe Frage in Laeufen beantwortete -- zwei
 * Orte fuer dieselbe Zahl, und die gehen irgendwann auseinander. Genau das
 * Muster, das die Nutzungsuebersicht vermeidet, indem sie aus den
 * Fachtabellen rechnet.
 *
 * Geblieben ist die Zusage aus Entscheidung G11: **weil wir zahlen, darf der
 * Verbrauch nicht offen sein** -- und geprueft wird **vor** dem Aufruf, denn
 * ein Lauf, der erst hinterher auffaellt, ist schon bezahlt.
 */
final class Kontingent
{
    public function __construct(
        private readonly Kontingente $kontingente,
        private readonly Nutzungsuebersicht $nutzung,
    ) {}

    public function zeitraum(?CarbonImmutable $jetzt = null): string
    {
        return ($jetzt ?? CarbonImmutable::now())->format('Y-m');
    }

    /** Wie viele Laeufe dieser Monat schon gekostet hat. */
    public function verbraucht(?CarbonImmutable $jetzt = null): int
    {
        $jetzt ??= CarbonImmutable::now();

        return $this->nutzung->agentenlaeufe($jetzt->startOfMonth(), $jetzt->endOfMonth());
    }

    public function gesamt(): int
    {
        return $this->kontingente->enthalten()['agentenlaeufe'];
    }

    public function rest(?CarbonImmutable $jetzt = null): int
    {
        return $this->kontingente->rest($jetzt)['agentenlaeufe'];
    }

    public function erschoepft(?CarbonImmutable $jetzt = null): bool
    {
        return $this->rest($jetzt) <= 0;
    }

    /** Anteil des Verbrauchten, zwischen 0 und 1. */
    public function anteil(?CarbonImmutable $jetzt = null): float
    {
        $gesamt = $this->gesamt();

        return $gesamt <= 0 ? 1.0 : min(1.0, $this->verbraucht($jetzt) / $gesamt);
    }
}
