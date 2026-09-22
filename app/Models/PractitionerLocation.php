<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBinaryUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Wer arbeitet wo.
 *
 * Ein ausdruecklicher Pivot und kein anonymes Verbindungstabellchen: die
 * Tabelle traegt eine organization_id, also braucht sie einen Schluessel, den
 * zusammengesetzten Fremdschluessel und den Global Scope wie jede andere
 * Mandantentabelle (Entscheidungen A1 bis A4).
 *
 * BelongsToMany::attach() legt die Zeile ueber diese Klasse an, sobald die
 * Beziehung `->using()` setzt -- sonst entstuende eine Zeile ohne id.
 */
class PractitionerLocation extends Pivot
{
    use BelongsToTenant;
    use HasBinaryUuid;

    public $incrementing = false;

    protected $table = 'practitioner_location';

    /** @var list<string> */
    protected $hidden = ['practitioner_id', 'location_id'];
}
