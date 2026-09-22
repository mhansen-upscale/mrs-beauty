<?php

declare(strict_types=1);

namespace App\Http\Requests\Katalog;

use App\Enums\Ability;
use App\Models\Treatment;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TreatmentRequest extends FormRequest
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
        $behandlung = $this->route('treatment');
        $eigene = $behandlung instanceof Treatment ? $behandlung->getKey() : null;
        $organisation = app(TenantContext::class)->requireId();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique(Treatment::class, 'name')->where('organization_id', $organisation)->ignore($eigene),
            ],
            'slug' => [
                'required', 'string', 'max:120', 'alpha_dash',
                Rule::unique(Treatment::class, 'slug')->where('organization_id', $organisation)->ignore($eigene),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:120'],

            'price_from_cents' => ['nullable', 'integer', 'min:0'],
            'price_to_cents' => ['nullable', 'integer', 'min:0', 'gte:price_from_cents'],

            // Entscheidung D14. Null waere kein Wert, sondern ein fehlender.
            'avg_revenue_cents' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avg_revenue_cents.required' => 'Ohne Umsatzschätzung lässt sich später kein ROAS berechnen.',
            'avg_revenue_cents.min' => 'Die Umsatzschätzung muss größer als null sein.',
            'price_to_cents.gte' => 'Der obere Preis darf nicht unter dem unteren liegen.',
        ];
    }
}
