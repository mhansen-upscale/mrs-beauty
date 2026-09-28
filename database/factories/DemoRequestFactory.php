<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DemoRequestStatus;
use App\Models\DemoRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoRequest>
 */
class DemoRequestFactory extends Factory
{
    protected $model = DemoRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'practice_name' => 'Praxis '.fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'city' => fake()->optional()->city(),
            'status' => DemoRequestStatus::New,
        ];
    }

    public function kontaktiert(): static
    {
        return $this->state(fn (): array => [
            'status' => DemoRequestStatus::Contacted,
            'status_changed_at' => CarbonImmutable::now(),
        ]);
    }

    public function erledigt(): static
    {
        return $this->state(fn (): array => [
            'status' => DemoRequestStatus::Closed,
            'status_changed_at' => CarbonImmutable::now(),
        ]);
    }
}
