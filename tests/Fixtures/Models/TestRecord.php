<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use App\Contracts\UsesBlindIndexes;
use App\Models\Concerns\HasBlindIndexes;
use App\Models\Concerns\MasksPersonalData;
use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nur im Testlauf. Weist die Mechanik aus WP-03 an einer echten Tabelle nach.
 *
 * @property string|null $label
 * @property string|null $email
 */
class TestRecord extends TenantModel implements HasPersonalData, UsesBlindIndexes
{
    use HasBlindIndexes;
    use MasksPersonalData;

    protected $table = 'test_records';

    protected $fillable = ['label', 'email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label' => Encrypted::class,
            'email' => Encrypted::class,
        ];
    }

    /**
     * Verschluesselt heisst personenbezogen -- sonst waere die
     * Verschluesselung sinnlos.
     *
     * @return list<string>
     */
    public function personalFields(): array
    {
        return ['label', 'email'];
    }

    /**
     * @return array<string, string>
     */
    public function blindIndexes(): array
    {
        return ['email' => 'email_bidx'];
    }

    /**
     * @return HasMany<TestChild, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(TestChild::class);
    }
}
