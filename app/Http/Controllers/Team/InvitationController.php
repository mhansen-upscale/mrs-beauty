<?php

declare(strict_types=1);

namespace App\Http\Controllers\Team;

use App\Enums\Ability;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\InviteMemberRequest;
use App\Models\Invitation;
use App\Notifications\TeamInvitation;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

final class InvitationController extends Controller
{
    public function store(InviteMemberRequest $request): RedirectResponse
    {
        $einladende = $request->user();

        [$einladung, $merkmal] = DB::transaction(function () use ($request, $einladende): array {
            $merkmal = Invitation::erzeugeMerkmal();

            $einladung = new Invitation;
            $einladung->email = (string) $request->validated('email');
            $einladung->role = Role::from((string) $request->validated('role'));
            $einladung->token_hash = Invitation::hashe($merkmal);
            $einladung->invited_by_user_id = $einladende?->getKey();
            $einladung->expires_at = now()->addDays(
                (int) config('mrs.invitations.ttl_days', 14)
            );
            $einladung->save();

            return [$einladung, $merkmal];
        });

        $organisation = app(TenantContext::class)->current();

        Notification::route('mail', $einladung->email)->notify(
            new TeamInvitation($einladung, $merkmal, $organisation->name ?? 'Ihrer Praxis')
        );

        return back();
    }

    /** Neues Merkmal, neue Frist -- das alte gilt danach nicht mehr. */
    public function resend(Request $request, Invitation $invitation): RedirectResponse
    {
        Gate::authorize(Ability::ManageTeam->value);

        abort_unless($invitation->istOffen(), 404);

        $merkmal = Invitation::erzeugeMerkmal();

        $invitation->token_hash = Invitation::hashe($merkmal);
        $invitation->expires_at = now()->addDays(
            (int) config('mrs.invitations.ttl_days', 14)
        );
        $invitation->save();

        $organisation = app(TenantContext::class)->current();

        Notification::route('mail', $invitation->email)->notify(
            new TeamInvitation($invitation, $merkmal, $organisation->name ?? 'Ihrer Praxis')
        );

        return back();
    }

    public function destroy(Request $request, Invitation $invitation): RedirectResponse
    {
        Gate::authorize(Ability::ManageTeam->value);

        // Widerrufen statt loeschen: der Vorgang bleibt nachvollziehbar.
        $invitation->revoked_at = now();
        $invitation->save();

        return back();
    }
}
