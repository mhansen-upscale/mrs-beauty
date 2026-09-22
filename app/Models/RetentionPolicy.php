<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RetentionAction;
use App\Enums\RetentionSubject;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;

/**
 * Eine Aufbewahrungsfrist dieser Praxis (Entscheidung C7).
 *
 * Die Fristen sind je Mandant konfigurierbar, die Liste der Gegenstaende ist
 * es nicht: was im Enum fehlt, wird nie geloescht, und das faellt erst auf,
 * wenn jemand danach fragt.
 *
 * @property RetentionSubject $subject
 * @property RetentionAction $action
 * @property int $retention_days
 * @property bool $is_active
 */
class RetentionPolicy extends TenantModel
{
    use Auditable;

    protected $fillable = ['subject', 'retention_days', 'action', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => RetentionSubject::class,
            'action' => RetentionAction::class,
            'retention_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Eine Frist gehoert mit Wert ins Protokoll: sie zu verlaengern ist eine
     * Entscheidung, die jemand erklaeren koennen muss.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['subject', 'retention_days', 'action', 'is_active'];
    }

    /** Alles, was vor diesem Zeitpunkt liegt, ist faellig. */
    public function stichtag(CarbonImmutable $jetzt): CarbonImmutable
    {
        return $jetzt->subDays($this->retention_days);
    }
}
