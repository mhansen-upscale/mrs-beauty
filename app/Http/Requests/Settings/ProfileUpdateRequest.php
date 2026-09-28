<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\ZweiFaktorVerfahren;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        assert($user instanceof User);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),

                // **Die Adresse ist der zweite Faktor** (WP-35). Wer sie hier
                // aendert, legt den Faktor mit dem Passwort allein in ein
                // anderes Postfach.
                function (string $attribut, mixed $wert, Closure $scheitern) use ($user): void {
                    if ($user->zweiFaktorVerfahren() === ZweiFaktorVerfahren::Email
                        && strtolower((string) $wert) !== strtolower($user->email)) {
                        $scheitern('Solange der zweite Faktor per E-Mail läuft, bleibt die Adresse. Wechseln Sie zuerst das Verfahren oder schalten Sie es ab.');
                    }
                },
            ],
        ];
    }
}
