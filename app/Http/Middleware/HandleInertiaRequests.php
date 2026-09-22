<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\ImpersonationContext;
use App\Enums\Ability;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    /**
     * @return array<string, mixed>|null
     */
    private function impersonation(): ?array
    {
        $kontext = app(ImpersonationContext::class);
        $sitzung = $kontext->current();

        if (! $sitzung instanceof ImpersonationSession) {
            return null;
        }

        return [
            'uuid' => $sitzung->uuid,
            'mode' => $sitzung->mode->value,
            'mode_label' => $sitzung->mode->label(),
            'masked' => $kontext->masks(),
            'reason' => $sitzung->reason,
            'expires_at' => $sitzung->expires_at->toIso8601String(),
        ];
    }

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $benutzer = $request->user();
        $organisation = app(TenantContext::class)->current();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $benutzer,
                'role' => $benutzer instanceof User ? $benutzer->role?->value : null,
            ],

            // Die Oberflaeche blendet danach aus, was jemand nicht darf. Das
            // ist eine Bequemlichkeit, keine Zugangskontrolle -- die steht in
            // den Gates und in den Controllern.
            'abilities' => $benutzer instanceof User
                ? collect(Ability::cases())
                    ->filter(fn (Ability $ability): bool => $benutzer->hasAbility($ability))
                    ->map(fn (Ability $ability): string => $ability->value)
                    ->values()
                    ->all()
                : [],

            'organization' => $organisation === null ? null : [
                'uuid' => $organisation->uuid,
                'name' => $organisation->name,
            ],

            // Solange eine Impersonation laeuft, ist sie in **jeder** Antwort
            // erkennbar (WP-05, Abnahmekriterium 18).
            'impersonation' => $this->impersonation(),
        ];
    }
}
