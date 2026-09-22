<?php

declare(strict_types=1);

namespace App\Http\Requests\Buchung;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Die Kontaktdaten der Interessentin.
 *
 * **Kein Freitextfeld.** Ein "Ihr Anliegen" fuellt sich auf einer
 * oeffentlichen Seite innerhalb von Tagen mit Gesundheitsdaten nach Art. 9
 * DSGVO -- abgegeben, bevor irgendjemand eingewilligt hat. Der
 * Behandlungswunsch laeuft ueber die Terminart (Entscheidung D2), Freitext
 * gehoert in die Inbox (WP-21).
 */
final class BuchungRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],

            // Ohne Einwilligung keine Buchung.
            'consent' => ['accepted'],

            // Honigtopf: ein Feld, das kein Mensch sieht und kein Mensch
            // ausfuellt. Wer es ausfuellt, ist keiner.
            'website' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent.accepted' => 'Ohne Ihre Einwilligung können wir den Termin nicht anlegen.',
            'website.prohibited' => 'Diese Anfrage konnte nicht verarbeitet werden.',
        ];
    }
}
