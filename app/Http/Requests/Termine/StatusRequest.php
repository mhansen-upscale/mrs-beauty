<?php

declare(strict_types=1);

namespace App\Http\Requests\Termine;

use App\Enums\Ability;
use App\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StatusRequest extends FormRequest
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
            // Die Absage laeuft ueber einen eigenen Weg: sie braucht einen
            // Grund und gibt die Slots frei.
            'status' => [
                'required',
                Rule::enum(AppointmentStatus::class)->except([AppointmentStatus::Cancelled]),
            ],
        ];
    }
}
