<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Treatment>
 */
class TreatmentFactory extends Factory
{
    protected $model = Treatment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words() ist als array|string typisiert, weil ein Parameter die
        // Rueckgabe umschaltet. Hier ist es immer ein Array.
        /** @var list<string> $woerter */
        $woerter = fake()->unique()->words(2);

        $name = ucfirst(implode(' ', $woerter));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => null,
            'category' => null,
            'price_from_cents' => 29000,
            'price_to_cents' => 49000,
            'avg_revenue_cents' => 39000,
            'is_active' => true,
        ];
    }

    public function inaktiv(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
