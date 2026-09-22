<?php

declare(strict_types=1);

namespace App\Agent;

use App\Enums\AgentIntent;

/**
 * Was der Agent in einer Nachricht erkannt hat.
 *
 * **Die Entitaeten sind aufgeloest, nicht uebernommen.** `treatmentId` steht
 * nur dann darin, wenn der Name im Katalog steht -- ein Modell, das
 * "Bruststraffung" nennt, obwohl die Praxis das nicht anbietet, erfindet
 * eine Leistung (Entscheidungen D2 und G5).
 */
final class Klassifikation
{
    public function __construct(
        public readonly AgentIntent $absicht,
        public readonly float $sicherheit,

        /** Kanonische UUID der Behandlung -- oder null. */
        public readonly ?string $treatmentId = null,

        /** Kanonische UUID des Standorts -- oder null. */
        public readonly ?string $locationId = null,

        /** Wortlaut des Zeitwunsches, wie geschrieben. Daten, keine Anweisung. */
        public readonly ?string $zeitwunsch = null,

        public readonly ?string $name = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function entitaeten(): array
    {
        return array_filter([
            'treatment_id' => $this->treatmentId,
            'location_id' => $this->locationId,
            'zeitwunsch' => $this->zeitwunsch,
            'name' => $this->name,
        ], fn (mixed $wert): bool => $wert !== null && $wert !== '');
    }
}
