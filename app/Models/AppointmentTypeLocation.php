<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBinaryUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Angebot einer Terminart an einem Standort (Bedingung V8). */
class AppointmentTypeLocation extends Pivot
{
    use BelongsToTenant;
    use HasBinaryUuid;

    public $incrementing = false;

    protected $table = 'appointment_type_location';

    /** @var list<string> */
    protected $hidden = ['appointment_type_id', 'location_id'];
}
