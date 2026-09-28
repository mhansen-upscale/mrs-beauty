<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OperatorRole;
use App\Enums\Role;
use App\Enums\ZweiFaktorVerfahren;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Das Geheimnis der Authenticator-States (WP-35). Oeffentlich, damit ein
     * Test den Code berechnen kann, den die App gerade zeigt.
     */
    public const TESTGEHEIMNIS = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Ausdruecklich gesetzt, auch wenn null: ohne das Attribut wirft
            // Model::shouldBeStrict() beim Zugriff darauf, weil das Feld nie
            // geladen wurde. Gilt fuer jede Spalte, die die Factory sonst
            // auslaesst.
            'organization_id' => null,
            'role' => null,
            'deactivated_at' => null,
            'operator_role' => null,
            'zwei_faktor_verfahren' => null,
            'zwei_faktor_geheimnis' => null,
            'zwei_faktor_wiederherstellung' => null,
            'zwei_faktor_bestaetigt_at' => null,
            'zwei_faktor_letzter_schritt' => null,
            'zwei_faktor_hinweis_ausgeblendet_at' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Benutzer einer bestimmten Organisation. */
    public function fuer(Organization $organization, ?Role $rolle = null): static
    {
        return $this->state(fn (array $attributes): array => array_filter([
            'organization_id' => $organization->getKey(),
            'role' => $rolle,
        ], fn (mixed $wert): bool => $wert !== null));
    }

    public function inhaberin(): static
    {
        return $this->state(fn (): array => ['role' => Role::Owner]);
    }

    public function deaktiviert(): static
    {
        return $this->state(fn (): array => ['deactivated_at' => now()]);
    }

    /**
     * Ein Konto im Team des Betreibers (WP-34a). Gehoert zu keiner
     * Organisation und hat keine Praxisrolle -- das haelt auch ein Trigger
     * in der Datenbank fest.
     */
    public function betreiber(OperatorRole $rolle): static
    {
        return $this->state(fn (): array => [
            'operator_role' => $rolle,
            'organization_id' => null,
            'role' => null,
        ]);
    }

    /** Super-Admin (WP-34): darf im Backoffice alles. */
    public function superAdmin(): static
    {
        return $this->betreiber(OperatorRole::SuperAdmin);
    }

    public function customerSuccess(): static
    {
        return $this->betreiber(OperatorRole::CustomerSuccess);
    }

    public function finanzen(): static
    {
        return $this->betreiber(OperatorRole::Finanzen);
    }

    /** Mit Authenticator-App als zweitem Faktor, bestaetigt (WP-35). */
    public function mitAuthenticator(string $geheimnis = self::TESTGEHEIMNIS): static
    {
        return $this->state(fn (): array => [
            'zwei_faktor_verfahren' => ZweiFaktorVerfahren::Authenticator,
            'zwei_faktor_geheimnis' => $geheimnis,
            'zwei_faktor_wiederherstellung' => [],
            'zwei_faktor_bestaetigt_at' => now(),
        ]);
    }

    /** Mit Code per E-Mail als zweitem Faktor, bestaetigt (WP-35). */
    public function mitEmailCode(): static
    {
        return $this->state(fn (): array => [
            'zwei_faktor_verfahren' => ZweiFaktorVerfahren::Email,
            'zwei_faktor_bestaetigt_at' => now(),
        ]);
    }
}
