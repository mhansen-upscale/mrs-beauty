<?php

declare(strict_types=1);

namespace App\Http\Controllers\Team;

use App\Audit\ImpersonationContext;
use App\Enums\Ability;
use App\Enums\Role;
use App\Http\Controllers\Audit\SupportPinController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\UpdateMemberRequest;
use App\Models\ImpersonationSession;
use App\Models\Invitation;
use App\Models\SupportPin;
use App\Models\User;
use App\ZweiFaktor\ZweiterFaktor;
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
                    'zweiFaktor' => $mitglied->zweiFaktorVerfahren()?->value,
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

            // Die Einmal-PIN (WP-34b): **im Klartext genau einmal**, direkt
            // nach dem Erzeugen, aus der Weiterleitung. Danach nur noch, dass
            // eine offen ist und bis wann.
            'neuePin' => $request->session()->get(SupportPinController::NEUE_PIN),
            'offenePin' => $this->offenePin(),
            'supportSitzungen' => $this->supportSitzungen(),

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

    /**
     * @return array{expires_at: string}|null
     */
    private function offenePin(): ?array
    {
        $pin = SupportPin::query()->offen()->where('expires_at', '>', now())->first();

        return $pin instanceof SupportPin ? ['expires_at' => $pin->expires_at->toIso8601String()] : null;
    }

    /**
     * Die juengsten Sitzungen des Supports in dieser Praxis: wann, wer,
     * warum, wie weit und wie freigegeben. „Sichtbar fuer beide Seiten"
     * (WP-34) heisst, die Praxis sieht, wer drin war.
     *
     * @return list<array<string, mixed>>
     */
    private function supportSitzungen(): array
    {
        $sitzungen = ImpersonationSession::query()
            ->latest('started_at')
            ->limit((int) config('mrs.impersonation.verlauf_eintraege'))
            ->get();

        // Ein geloeschtes Betreiberkonto hinterlaesst seine Sitzungen.
        $namen = User::query()
            ->whereIn('id', $sitzungen->map(fn (ImpersonationSession $sitzung): mixed => $sitzung->getAttribute('impersonator_user_id'))->unique()->all())
            ->pluck('name', 'id');

        return array_values($sitzungen
            ->map(fn (ImpersonationSession $sitzung): array => [
                'uuid' => $sitzung->uuid,
                'betreiber' => (string) ($namen->get((string) $sitzung->getAttribute('impersonator_user_id')) ?? 'Gelöschtes Betreiberkonto'),
                'reason' => $sitzung->reason,
                'mode' => $sitzung->mode->label(),
                'vollzugriff' => $sitzung->hatVollzugriff(),
                'freigabeweg' => $sitzung->approval_method?->label(),
                'started_at' => $sitzung->started_at->toIso8601String(),
                'ended_at' => $sitzung->ended_at?->toIso8601String(),
                'expires_at' => $sitzung->expires_at->toIso8601String(),
                'laeuft' => $sitzung->laeuft(),
            ])
            ->all());
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
     * Setzt den zweiten Faktor eines Mitglieds zurueck -- das Handy ist weg
     * (WP-35).
     *
     * **Der zweite Faktor schuetzt auch vor Kolleginnen, die das Passwort
     * kennen.** Deshalb: nur mit dem eigenen Passwort, nie der eigene, nie
     * waehrend einer Impersonation, und den einer Inhaberin nur eine
     * Inhaberin (abgeleitet, zu bestaetigen).
     */
    public function zweiFaktorZuruecksetzen(Request $request, User $member, ZweiterFaktor $zweiterFaktor): RedirectResponse
    {
        Gate::authorize(Ability::ManageTeam->value);

        // Vor der Organisationspruefung: der Support hat keine eigene Praxis
        // und bekaeme sonst 404 statt eines klaren Nein.
        abort_if(app(ImpersonationContext::class)->isActive(), 403);

        $this->stelleSicherDassEigeneOrganisation($request, $member);

        abort_if($member->hasRole(Role::Owner) && ! $request->user()?->hasRole(Role::Owner), 403);

        $request->validate(['current_password' => ['required', 'current_password']]);

        if ($member->getKey() === $request->user()?->getKey()) {
            throw ValidationException::withMessages([
                'member' => 'Den eigenen zweiten Faktor ändern Sie unter Einstellungen.',
            ]);
        }

        if ($member->hatZweiFaktor()) {
            $zweiterFaktor->setzeZurueck($member);
        }

        return back()->with('erfolg', "Der zweite Faktor von {$member->name} ist zurückgesetzt. Die Person meldet sich jetzt nur mit Passwort an.");
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
