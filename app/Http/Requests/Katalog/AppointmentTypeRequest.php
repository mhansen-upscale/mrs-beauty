<?php

declare(strict_types=1);

namespace App\Http\Requests\Katalog;

use App\Enums\Ability;
use App\Models\AppointmentType;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AppointmentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Ability::ManageCatalog->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $art = $this->route('appointmentType');
        $eigene = $art instanceof AppointmentType ? $art->getKey() : null;
        $organisation = app(TenantContext::class)->requireId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:120', 'alpha_dash',
                Rule::unique(AppointmentType::class, 'slug')->where('organization_id', $organisation)->ignore($eigene),
            ],
            'description' => ['nullable', 'string', 'max:5000'],

            'treatment' => ['nullable', 'string', 'uuid'],

            // Fuenf Minuten ist die Untergrenze des Rasters aus WP-10.
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'lead_time_hours' => ['required', 'integer', 'min:0', 'max:8760'],

            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_public' => ['boolean'],

            'practitioners' => ['array'],
            'practitioners.*' => ['string', 'uuid'],
            'locations' => ['array'],
            'locations.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duration_minutes.min' => 'Kürzer als fünf Minuten lässt sich nicht rastern.',
        ];
    }
}
