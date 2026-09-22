<?php

declare(strict_types=1);

namespace App\Http\Requests\Team;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Ability::ManageTeam->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->requireId();

        return [
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',

                // Wer schon im Team ist, wird nicht noch einmal eingeladen.
                Rule::unique(User::class, 'email')
                    ->where('organization_id', $organizationId),

                // Und eine zweite offene Einladung an dieselbe Adresse gibt es
                // auch nicht -- die Datenbank haelt das ueber open_guard
                // zusaetzlich fest.
                Rule::unique('invitations', 'email')
                    ->where('organization_id', $organizationId)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at'),
            ],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Diese Adresse gehoert bereits zum Team oder wurde schon eingeladen.',
        ];
    }
}
