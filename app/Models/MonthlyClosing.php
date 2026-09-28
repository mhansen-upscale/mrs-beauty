<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Der eingefrorene Monat einer Praxis (WP-34d, B19).
 *
 * **Warum eingefroren:** `subscriptions` kennt nur den Jetzt-Zustand. Ob eine
 * Praxis im Maerz pausiert war, laesst sich im Mai nicht mehr rechnen.
 *
 * @property string $month
 * @property string $zugang
 * @property int $grundpreis_cents
 * @property int $einrichtung_cents
 * @property int $aufstockungen_cents
 * @property int $bilder_cents
 * @property int $servicefenster_cents
 * @property int $sprachmodell_agent_cents
 * @property int $sprachmodell_anzeigen_cents
 * @property int $whatsapp_cents
 * @property int $bildkosten_cents
 * @property int $zahlungsverkehr_cents
 * @property list<string>|null $fehlende_saetze
 * @property CarbonImmutable $closed_at
 */
class MonthlyClosing extends TenantModel
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grundpreis_cents' => 'integer',
            'einrichtung_cents' => 'integer',
            'aufstockungen_cents' => 'integer',
            'bilder_cents' => 'integer',
            'servicefenster_cents' => 'integer',
            'sprachmodell_agent_cents' => 'integer',
            'sprachmodell_anzeigen_cents' => 'integer',
            'whatsapp_cents' => 'integer',
            'bildkosten_cents' => 'integer',
            'zahlungsverkehr_cents' => 'integer',
            'fehlende_saetze' => 'array',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
