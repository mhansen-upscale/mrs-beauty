<?php

declare(strict_types=1);

namespace App\Http\Requests\Stammdaten;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Gemeinsame Regeln fuer Abwesenheiten und Schliesszeiten.
 *
 * Beide sind absolute Zeitraeume in UTC -- anders als die wiederkehrende
 * Arbeitszeit, die eine Regel in Ortszeit ist.
 */
abstract class ZeitraumRequest extends FormRequest
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
        return [
            ...$this->grundRegel(),
            'note' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    abstract protected function grundRegel(): array;

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => 'Das Ende muss nach dem Beginn liegen.',
        ];
    }
}
