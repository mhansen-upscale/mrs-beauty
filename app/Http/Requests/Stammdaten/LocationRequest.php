<?php

declare(strict_types=1);

namespace App\Http\Requests\Stammdaten;

use App\Enums\Ability;
use App\Models\Location;
use App\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Ability::ManageMasterData->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $standort = $this->route('location');

        return [
            'name' => ['required', 'string', 'max:255'],

            // Ohne Zeitzone ist jede fachliche Zeitaussage des Standorts
            // wertlos (Entscheidung A8). Deshalb Pflicht, und deshalb gegen
            // die Liste der Datenbank geprueft -- 'Europe/Berlinn' waere sonst
            // ein Fehler, der erst in WP-10 auffaellt.
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],

            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],

            'slug' => [
                'required', 'string', 'max:120', 'alpha_dash',
                Rule::unique(Location::class, 'slug')
                    ->where('organization_id', app(TenantContext::class)->requireId())
                    ->ignore($standort instanceof Location ? $standort->getKey() : null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.in' => 'Diese Zeitzone kennt das System nicht.',
            'timezone.required' => 'Ein Standort ohne Zeitzone waere nicht buchbar.',
        ];
    }
}
