<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Tenancy\KeyRing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'settings' => [],
        ];
    }

    /**
     * Jede Organisation bekommt ihren Schluesselsatz. Ohne ihn liesse sich
     * kein verschluesseltes Feld schreiben -- in Tests waere das ein
     * Stolperstein ohne Erkenntniswert.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            app(KeyRing::class)->issue($organization);
        });
    }

    /** Ohne Schluesselsatz, fuer Tests der Krypto-Loeschung. */
    public function ohneSchluessel(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            $organization->encryptionKeys()->delete();
            app(KeyRing::class)->flush();
        });
    }
}
