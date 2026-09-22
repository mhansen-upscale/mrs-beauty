<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Macht aus jeder Faehigkeit ein Gate.
 *
 * Damit funktionieren $user->can('team.manage'), Gate::authorize() und die
 * can-Middleware ueberall gleich, ohne dass irgendwo eine zweite Liste
 * gepflegt wird.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (Ability::cases() as $ability) {
            Gate::define(
                $ability->value,
                fn (User $user): bool => $user->hasAbility($ability)
            );
        }
    }
}
