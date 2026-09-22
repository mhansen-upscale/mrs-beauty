<?php

declare(strict_types=1);

namespace App\Http\Controllers\Team;

use App\Enums\Ability;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\UpdateMemberRequest;
use App\Models\ImpersonationSession;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MemberController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Ability::ManageTeam->value);

        return Inertia::render('organisation/Team', [
            'members' => User::query()
                ->derOrganisation()
                ->orderBy('name')
                ->get()
                ->map(fn (User $mitglied): array => [
                    'uuid' => $mitglied->uuid,
                    'name' => $mitglied->name,
                    'email' => $mitglied->email,
                    'role' => $mitglied->role?->value,
                    'deactivated' => $mitglied->isDeactivated(),
                    'verified' => $mitglied->hasVerifiedEmail(),
                    'self' => $mitglied->getKey() === $request->user()?->getKey(),
                ])
                ->values(),

            'invitations' => Invitation::query()
                ->offen()
                ->orderBy('created_at')
                ->get()
                ->map(fn (Invitation $einladung): array => [
                    'uuid' => $einladung->uuid,
                    'email' => $einladung->email,
                    'role' => $einladung->role->value,
                    'expires_at' => $einladung->expires_at->toIso8601String(),
                ])
                ->values(),

            // Eine laufende Impersonation des Supports, die auf Freigabe
            // wartet. Die Inhaberin entscheidet, niemand sonst (Entscheidung C4).
            'supportSession' => $this->offeneSupportanfrage(),

            'roles' => collect(Role::assignable())
                ->map(fn (Role $rolle): array => [
                    'value' => $rolle->value,
                    'label' => $rolle->label(),
                    'description' => $rolle->description(),
                ])
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function offeneSupportanfrage(): ?array
    {
        $sitzung = ImpersonationSession::query()->laufend()->first();

        if (! $sitzung instanceof ImpersonationSession || $sitzung->hatVollzugriff()) {
            return null;
        }

        return [
            'uuid' => $sitzung->uuid,
            'reason' => $sitzung->reason,
            'started_at' => $sitzung->started_at->toIso8601String(),
            'expires_at' => $sitzung->expires_at->toIso8601String(),
        ];
    }

    public function update(UpdateMemberRequest $request, User $member): RedirectResponse
    {
        $this->stelleSicherDassEigeneOrganisation($request, $member);

        $neueRolle = Role::from((string) $request->validated('role'));

        // Die letzte Inhaberin bleibt Inhaberin. Sonst sperrt sich die Praxis
        // aus ihrem eigenen Produkt aus.
        if ($neueRolle !== Role::Owner && $member->istLetzteInhaberin()) {
            throw ValidationException::withMessages([
                'role' => 'Die letzte Inhaberin kann ihre Rolle nicht abgeben.',
            ]);
        }

        $member->role = $neueRolle;
        $member->save();

        return back();
    }

    public function deactivate(Request $request, User $member): RedirectResponse
    {
        Gate::authorize(Ability::ManageTeam->value);
        $this->stelleSicherDassEigeneOrganisation($request, $member);

        if ($member->istLetzteInhaberin()) {
            throw ValidationException::withMessages([
                'member' => 'Die letzte Inhaberin kann nicht deaktiviert werden.',
            ]);
        }

        if ($member->getKey() === $request->user()?->getKey()) {
            throw ValidationException::withMessages([
                'member' => 'Sie koennen sich nicht selbst deaktivieren.',
            ]);
        }

        $member->deactivated_at = now();
        $member->save();

        return back();
    }

    public function reactivate(Request $request, User $member): RedirectResponse
    {
        Gate::authorize(Ability::ManageTeam->value);
        $this->stelleSicherDassEigeneOrganisation($request, $member);

        $member->deactivated_at = null;
        $member->save();

        return back();
    }

    /**
     * users ist kein TenantModel -- der Global Scope schuetzt hier niemanden.
     * Route-Model-Binding wuerde ohne diese Pruefung jede Person jeder
     * Organisation liefern.
     */
    private function stelleSicherDassEigeneOrganisation(Request $request, User $member): void
    {
        abort_unless(
            $member->organization_id !== null
                && $member->organization_id === $request->user()?->organization_id,
            404
        );
    }
}
