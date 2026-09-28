<?php

declare(strict_types=1);

namespace App\Http\Requests\Oeffentlich;

use App\Oeffentlich\Formularmerkmal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Die Demo-Anfrage von der Startseite (WP-38).
 *
 * **Kein Freitextfeld** (D2). Ein "Ihre Nachricht" fuellt sich auf einer
 * oeffentlichen Seite mit Behandlungswuenschen -- auch wenn nur Praxen
 * gemeint sind. Fuer einen Rueckruf reichen Name, Praxis und ein Kontaktweg;
 * alles Weitere klaert das Gespraech.
 */
final class DemoanfrageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'practice_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],

            // Honigtopf: ein Feld, das kein Mensch sieht und kein Mensch
            // ausfuellt. Wer es ausfuellt, ist keiner (wie WP-12).
            'website' => ['prohibited'],

            // Wann die Seite geladen wurde -- gegen Bots, ohne Dritte.
            'merkmal' => ['required', 'string'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $pruefung): void {
                if ($pruefung->errors()->isNotEmpty()) {
                    return;
                }

                $fehler = app(Formularmerkmal::class)->pruefe($this->string('merkmal')->toString());

                if ($fehler !== null) {
                    $pruefung->errors()->add('merkmal', $fehler);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'website.prohibited' => 'Diese Anfrage konnte nicht verarbeitet werden.',
            'merkmal.required' => 'Bitte laden Sie die Seite neu und senden Sie das Formular noch einmal.',
        ];
    }
}
