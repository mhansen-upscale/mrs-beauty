<?php

declare(strict_types=1);

namespace App\Http\Requests\Stammdaten;

use App\Enums\ClosureReason;
use Illuminate\Validation\Rule;

final class ClosureRequest extends ZeitraumRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function grundRegel(): array
    {
        return ['reason' => ['required', Rule::enum(ClosureReason::class)]];
    }
}
