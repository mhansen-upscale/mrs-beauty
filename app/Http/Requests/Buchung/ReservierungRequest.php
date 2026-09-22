<?php

declare(strict_types=1);

namespace App\Http\Requests\Buchung;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Die Wahl eines Zeitpunkts auf der oeffentlichen Buchungsseite.
 *
 * Alle drei IDs werden **innerhalb** des aus dem Slug aufgeloesten Mandanten
 * gesucht -- der Global Scope erledigt das. Eine gueltige UUID aus einer
 * fremden Praxis findet hier nichts.
 */
final class ReservierungRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'appointment_type' => ['required', 'string', 'uuid'],
            'location' => ['required', 'string', 'uuid'],
            'practitioner' => ['required', 'string', 'uuid'],
            'blocked_from' => ['required', 'date'],
        ];
    }
}
