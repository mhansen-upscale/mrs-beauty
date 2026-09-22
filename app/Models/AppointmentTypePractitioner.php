<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBinaryUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Freigabe eines Behandlers fuer eine Terminart (Bedingung V7). */
class AppointmentTypePractitioner extends Pivot
{
    use BelongsToTenant;
    use HasBinaryUuid;

    public $incrementing = false;

    protected $table = 'appointment_type_practitioner';

    /** @var list<string> */
    protected $hidden = ['appointment_type_id', 'practitioner_id'];
}
