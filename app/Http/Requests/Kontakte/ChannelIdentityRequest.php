<?php

declare(strict_types=1);

namespace App\Http\Requests\Kontakte;

use App\Enums\Ability;
use App\Enums\ChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChannelIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAbility(Ability::ManageContacts) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(ChannelType::class)],
            'external_id' => ['required', 'string', 'max:190'],
            'display_name' => ['nullable', 'string', 'max:190'],
        ];
    }
}
