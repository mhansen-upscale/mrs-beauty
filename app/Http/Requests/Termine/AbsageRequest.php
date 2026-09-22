<?php

declare(strict_types=1);

namespace App\Http\Requests\Termine;

use App\Enums\Ability;
use App\Enums\CancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AbsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Ability::ManageAppointments->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Eine abgeschlossene Aufzaehlung und kein Freitext -- ein
            // Begruendungsfeld fuellt sich mit Gesundheitsdaten (P1, Regel 3).
            'reason' => ['required', Rule::enum(CancellationReason::class)],
        ];
    }
}
