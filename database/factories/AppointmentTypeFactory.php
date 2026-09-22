<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppointmentType;
use App\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppointmentType>
 */
class AppointmentTypeFactory extends Factory
{
    protected $model = AppointmentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Termin '.fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => null,
            'treatment_id' => null,
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'lead_time_hours' => 0,
            'color' => '#6366f1',
            'is_public' => true,
            'is_active' => true,
        ];
    }

    public function fuer(Treatment $behandlung): static
    {
        return $this->state(fn (): array => ['treatment_id' => $behandlung->getKey()]);
    }

    public function mitRuestzeit(int $davor, int $danach): static
    {
        return $this->state(fn (): array => [
            'buffer_before_minutes' => $davor,
            'buffer_after_minutes' => $danach,
        ]);
    }

    public function mitVorlauf(int $stunden): static
    {
        return $this->state(fn (): array => ['lead_time_hours' => $stunden]);
    }

    public function inaktiv(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
