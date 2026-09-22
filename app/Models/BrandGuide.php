<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BrandAddress;
use App\Enums\BrandTone;
use App\Models\Concerns\Auditable;

/**
 * Wie diese Praxis klingt und womit sie wirbt.
 *
 * Einer je Mandant. Was hier nicht steht, wird in WP-31 nicht erfunden --
 * ein leeres Profil ergibt einen duennen Vorschlag, und das ist ehrlicher
 * als ein voller aus Platzhaltern.
 *
 * @property BrandTone|null $tone
 * @property BrandAddress|null $address_form
 * @property string|null $audience
 * @property string|null $positioning
 * @property string|null $claim
 * @property string|null $no_go_topics
 */
class BrandGuide extends TenantModel
{
    use Auditable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tone' => BrandTone::class,
            'address_form' => BrandAddress::class,
        ];
    }

    /**
     * Ohne Werte: der Ton ist eine Geschmacksfrage der Praxis, kein
     * Personendatum -- aber wer ihn geaendert hat, gehoert ins Protokoll.
     *
     * @return list<string>
     */
    public function auditableValues(): array
    {
        return ['tone', 'address_form'];
    }
}
