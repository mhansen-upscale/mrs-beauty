<?php

declare(strict_types=1);

namespace App\Http\Requests\Kontakte;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAbility(Ability::ManageContacts) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],

            // **Bewusst ohne Formatvorgabe.** Was sich nicht nach E.164
            // bringen laesst, wird gespeichert und nur nicht indiziert -- eine
            // unleserliche Nummer ist ein Kontaktweg, den jemand abtippen
            // kann (WP-16).
            'phone' => ['nullable', 'string', 'max:60'],
        ];
    }
}
