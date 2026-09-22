<?php

declare(strict_types=1);

namespace App\Http\Requests\Termine;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Eine neue Buchung aus der internen Terminverwaltung.
 *
 * Die Zeit kommt als `blocked_from` herein -- der Beginn der **belegten**
 * Strecke, nicht der angezeigten. Aus ihr leitet Slotvorschlag::ab() beides
 * ab; wer stattdessen die angezeigte Zeit schickte, verschoebe jeden Termin
 * um die Ruestzeit davor.
 */
final class TerminRequest extends FormRequest
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
            'appointment_type' => ['required', 'string', 'uuid'],
            'practitioner' => ['required', 'string', 'uuid'],
            'location' => ['required', 'string', 'uuid'],
            'blocked_from' => ['required', 'date'],

            // Ein bestehender Kontakt **oder** ein neuer, nicht beides.
            'contact' => ['nullable', 'string', 'uuid'],
            'first_name' => ['required_without:contact', 'nullable', 'string', 'max:120'],
            'last_name' => ['required_without:contact', 'nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],

            'uebersteuern' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required_without' => 'Ohne bestehenden Kontakt braucht es einen Vornamen.',
            'last_name.required_without' => 'Ohne bestehenden Kontakt braucht es einen Nachnamen.',
        ];
    }
}
