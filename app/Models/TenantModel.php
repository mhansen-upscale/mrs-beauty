<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBinaryUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Basisklasse fuer jedes Modell mit Mandantenbezug (Entscheidung A3).
 *
 * @property string $id Rohbytes. Die lesbare Form ist $uuid.
 * @property string $organization_id Rohbytes.
 * @property-read string|null $uuid
 *
 * Wer sie umgeht, wird vom Architektur-Test in
 * tests/Feature/Tenancy/ArchitekturTest.php erwischt. Das ist Absicht: MySQL
 * bietet keine Row Level Security, also ist diese Klasse zusammen mit dem
 * Test der einzige strukturelle Schutz.
 */
abstract class TenantModel extends Model
{
    use BelongsToTenant;
    use HasBinaryUuid;
}
