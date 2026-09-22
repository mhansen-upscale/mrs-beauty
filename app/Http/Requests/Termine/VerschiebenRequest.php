<?php

declare(strict_types=1);

namespace App\Http\Requests\Termine;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

final class VerschiebenRequest extends FormRequest
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
            'practitioner' => ['required', 'string', 'uuid'],
            'location' => ['required', 'string', 'uuid'],
            'blocked_from' => ['required', 'date'],
            'uebersteuern' => ['boolean'],
        ];
    }
}
