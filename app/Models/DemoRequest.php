<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HasPersonalData;
use App\Enums\DemoRequestStatus;
use App\Models\Concerns\HasBinaryUuid;
use App\Models\Concerns\MasksPersonalData;
use Carbon\CarbonImmutable;
use Database\Factories\DemoRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine Demo-Anfrage von der Startseite (WP-38).
 *
 * **Global, kein TenantModel:** Wer anfragt, ist noch keine Praxis. Deshalb
 * der App-Schluessel (`encrypted`) statt des Schluessels je Organisation, den
 * es hier nicht gibt -- wie beim Plattformversand.
 *
 * **Nicht Auditable.** Jede oeffentliche Anfrage schriebe sonst eine Zeile ins
 * Protokoll; protokolliert wird, was ein Betreiber mit ihr tut.
 *
 * @property string $name
 * @property string $practice_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $city
 * @property DemoRequestStatus $status
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class DemoRequest extends Model implements HasPersonalData
{
    use HasBinaryUuid;

    /** @use HasFactory<DemoRequestFactory> */
    use HasFactory;

    use MasksPersonalData;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'new',
    ];

    /**
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['name', 'practice_name', 'email', 'phone', 'city'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'practice_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'city' => 'encrypted',
            'status' => DemoRequestStatus::class,
            'status_changed_at' => 'immutable_datetime',
        ];
    }
}
