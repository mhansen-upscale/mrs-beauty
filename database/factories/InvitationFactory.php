<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Reception,
            'token_hash' => Invitation::hashe(Invitation::erzeugeMerkmal()),
            'invited_by_user_id' => null,
            'expires_at' => now()->addDays((int) config('mrs.invitations.ttl_days', 14)),
        ];
    }

    public function abgelaufen(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function widerrufen(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function angenommen(): static
    {
        return $this->state(fn (): array => ['accepted_at' => now()]);
    }
}
