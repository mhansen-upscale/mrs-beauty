<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Standort '.fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'timezone' => 'Europe/Berlin',
            'street' => fake()->streetAddress(),
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'country' => 'DE',
            'phone' => null,
            'email' => null,
            'is_active' => true,
        ];
    }

    public function inZone(string $zeitzone): static
    {
        return $this->state(fn (): array => ['timezone' => $zeitzone]);
    }

    public function inaktiv(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
