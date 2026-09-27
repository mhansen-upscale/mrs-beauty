<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionChangeAction;
use App\Enums\SubscriptionChangeStatus;
use Carbon\CarbonImmutable;

/**
 * Ein Eingriff des Betreibers in das Abo einer Praxis (WP-34c, B17).
 *
 * **Der Auftrag mit seinem sichtbaren Stand** (Regel 4), keine zweite
 * Zustandsfuehrung: was aus einem erledigten Auftrag fuer das Abo folgt,
 * meldet Stripe ueber den Webhook. Ein gescheiterter Auftrag steht im
 * Mandantenblatt und in der Betriebslage, nicht nur im Log.
 *
 * `parameters` traegt nichts Personenbezogenes -- ein Datum, eine Anzahl
 * Tage, und ob ohne Stripe gearbeitet wurde.
 *
 * @property SubscriptionChangeAction $action
 * @property array<string, mixed>|null $parameters
 * @property string $reason
 * @property SubscriptionChangeStatus $status
 * @property string|null $error
 * @property string|null $requested_by_user_id
 * @property string $idempotency_key
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 */
class SubscriptionChange extends TenantModel
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['requested_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => SubscriptionChangeAction::class,
            'status' => SubscriptionChangeStatus::class,
            'parameters' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** Ohne Stripe erledigt -- der Testbetrieb, sichtbar gekennzeichnet. */
    public function ohneStripe(): bool
    {
        return (bool) ($this->parameters['ohne_stripe'] ?? false);
    }
}
