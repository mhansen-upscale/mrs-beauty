<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nur im Testlauf. Haengt am zusammengesetzten Fremdschluessel
 * (test_record_id, organization_id) -- Entscheidung A2.
 */
class TestChild extends TenantModel
{
    protected $table = 'test_children';

    protected $fillable = ['title', 'test_record_id'];

    /** @var list<string> */
    protected $hidden = ['test_record_id'];

    /**
     * @return BelongsTo<TestRecord, $this>
     */
    public function testRecord(): BelongsTo
    {
        return $this->belongsTo(TestRecord::class);
    }
}
