<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Modellzweck;

/**
 * Ein Aufruf des Sprachmodells ausserhalb eines Assistenzlaufs (WP-34d).
 *
 * @property Modellzweck $purpose
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_tenth_cents
 */
class ModelCall extends TenantModel
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => Modellzweck::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_tenth_cents' => 'integer',
        ];
    }
}
