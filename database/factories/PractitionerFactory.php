<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Practitioner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Practitioner>
 */
class PractitionerFactory extends Factory
{
    protected $model = Practitioner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Dr. med.',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'user_id' => null,
            'is_active' => true,
        ];
    }

    public function inaktiv(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
