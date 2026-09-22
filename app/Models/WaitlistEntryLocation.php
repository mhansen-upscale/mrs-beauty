<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBinaryUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Standortwunsch eines Wartelisteneintrags (Bedingung K3). */
class WaitlistEntryLocation extends Pivot
{
    use BelongsToTenant;
    use HasBinaryUuid;

    public $incrementing = false;

    protected $table = 'waitlist_entry_location';

    /** @var list<string> */
    protected $hidden = ['waitlist_entry_id', 'location_id'];
}
