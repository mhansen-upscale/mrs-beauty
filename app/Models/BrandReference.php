<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BrandReferenceKind;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Ein Stueck Referenzmaterial -- mit der Erklaerung, die dazu abgegeben
 * wurde.
 *
 * **Der Wortlaut steht dabei, nicht nur ein Haekchen.** Wer spaeter fragt,
 * was die Praxis zugesichert hat, braucht den Text von damals und nicht den
 * von heute. Dasselbe Vorgehen wie bei den Einwilligungen in WP-18.
 *
 * @property BrandReferenceKind $kind
 * @property string $title
 * @property string|null $note
 * @property string $declaration_text
 * @property string|null $declared_by_user_id
 * @property CarbonImmutable $declared_at
 */
class BrandReference extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['declared_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BrandReferenceKind::class,
            'declared_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['kind'];
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
