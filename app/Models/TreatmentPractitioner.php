<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBinaryUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Freigabe eines Behandlers fuer eine Behandlung.
 *
 * Wie jede Verknuepfungstabelle des Produkts traegt sie eine eigene UUID und
 * eine organization_id -- ein reiner Pivot ohne Modell bekaeme weder das eine
 * noch das andere gesetzt (Entscheidungen A4 und A6).
 */
class TreatmentPractitioner extends Pivot
{
    use BelongsToTenant;
    use HasBinaryUuid;

    public $incrementing = false;

    protected $table = 'treatment_practitioner';

    /** @var list<string> */
    protected $hidden = ['treatment_id', 'practitioner_id'];
}
