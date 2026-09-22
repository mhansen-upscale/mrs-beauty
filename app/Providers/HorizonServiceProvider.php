<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Wer Horizon außerhalb von `local` sehen darf.
     *
     * Bis WP-34 (Super-Admin-Backoffice) gibt es keine Rolle, an der sich das
     * festmachen ließe. Solange verweigert das Gate außerhalb von `local`
     * ausnahmslos — die Warteschlange zeigt Absenderadressen, Empfänger und
     * Nutzlasten von Jobs, also genau die Daten, die Regel 3 schützt.
     *
     * Ein offenes Horizon in Produktion ist ein Datenleck mit hübscher
     * Oberfläche.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            unset($user);

            return $this->app->environment('local');
        });
    }
}
