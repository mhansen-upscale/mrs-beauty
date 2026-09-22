<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\MessageCostCategory;

/**
 * Was der Anbieter nach einem Versand zurueckmeldet.
 *
 * **Die Kostenkategorie kommt von ihm, nicht von uns.**
 * `docs/integrationen/meta.md` ist an der Stelle ausdruecklich: sie wird je
 * Nachricht aus der Antwort uebernommen, nie geschaetzt. Eine geschaetzte
 * Kategorie wird zu einer geschaetzten Rechnung.
 *
 * **Und sie ist optional**, weil nicht jeder Kanal sie sofort nennt: die
 * Sendeantwort von WhatsApp traegt nur die Kennung, `pricing` kommt erst mit
 * der Statusrueckmeldung (WP-20a). `null` heisst dann unbekannt --
 * `MessageCostCategory::None` waere an dieser Stelle die Schaetzung
 * "kostenlos", also genau die, die am teuersten wird.
 */
final class Versandergebnis
{
    public function __construct(
        public readonly string $externeId,
        public readonly ?MessageCostCategory $kategorie = null,
    ) {}
}
