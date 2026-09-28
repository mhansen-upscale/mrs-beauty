<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Audit\AuditLogger;
use App\Backoffice\Betreiberkonten;
use App\Enums\AuditEvent;
use App\Enums\OperatorRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\ZweiFaktor\ZweiterFaktor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Konten des Betreiberteams (WP-34a, `betreiber.verwalten`).
 *
 * Ein Betreiberkonto entsteht hier oder auf der Konsole (`mrs:betreiber`),
 * nie ueber die Registrierung. Das Passwort setzt die Person selbst ueber den
 * Link, den sie per Mail bekommt -- niemand sonst kennt es.
 *
 * **Den letzten aktiven Super-Admin nimmt niemand weg**, sich selbst auch
 * nicht: ohne ihn kaeme niemand mehr an diese Seite, ausser ueber die
 * Konsole.
 *
 * Jede Aenderung steht im Protokoll ohne Organisation -- ein Betreiberkonto
 * gehoert keiner Praxis.
 */
final class BetreiberController extends Controller
{
    public function __construct(
        private readonly Betreiberkonten $konten,
        private readonly AuditLogger $protokoll,
    ) {}

    public function index(): Response
    {
        return Inertia::render('backoffice/Betreiber', [
            // Die Konten des eigenen Teams -- keine Person einer Praxis. users
            // ist kein TenantModel, die Einschraenkung steht deshalb hier.
            'betreiber' => User::query()
                ->whereNotNull('operator_role')
                ->orderBy('name')
                ->get()
                ->map(fn (User $konto): array => [
                    'uuid' => $konto->uuid,
                    'name' => $konto->name,
                    'email' => $konto->email,
                    'rolle' => $konto->betreiberRolle()?->value,
                    'rolleLabel' => $konto->betreiberRolle()?->label(),
                    'deaktiviert' => $konto->isDeactivated(),
                    'letzterSuperAdmin' => $konto->istLetzterSuperAdmin(),
                    'zweiFaktor' => $konto->zweiFaktorVerfahren()?->value,
                ])
                ->values(),
            'rollen' => array_map(fn (OperatorRole $rolle): array => [
                'wert' => $rolle->value,
                'label' => $rolle->label(),
                'beschreibung' => $rolle->description(),
            ], OperatorRole::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // **Gegen alle Konten geprueft**, nicht nur gegen Betreiber: eine
            // Adresse gehoert genau einem Konto, sonst weiss die Anmeldung
            // nicht, wen sie meint.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'rolle' => ['required', Rule::enum(OperatorRole::class)],
            ...BackofficeController::PASSWORT,
        ]);

        $this->konten->lege((string) $daten['email'], (string) $daten['name'], OperatorRole::from((string) $daten['rolle']));

        return back()->with('erfolg', 'Konto angelegt. Die Person bekommt einen Link, um ihr Passwort zu setzen.');
    }

    public function rolle(Request $request, User $betreiber): RedirectResponse
    {
        $this->nurBetreiber($betreiber);

        $daten = $request->validate([
            'rolle' => ['required', Rule::enum(OperatorRole::class)],
            ...BackofficeController::PASSWORT,
        ]);

        $vorher = $betreiber->betreiberRolle();
        $nachher = OperatorRole::from((string) $daten['rolle']);

        if ($vorher === $nachher) {
            return back();
        }

        if ($betreiber->is($request->user()) || $betreiber->istLetzterSuperAdmin()) {
            throw ValidationException::withMessages([
                'rolle' => $betreiber->istLetzterSuperAdmin()
                    ? 'Das ist der letzte aktive Super-Admin. Ernennen Sie zuerst einen weiteren.'
                    : 'Die eigene Rolle ändert jemand anderes.',
            ]);
        }

        $betreiber->operator_role = $nachher;
        $betreiber->save();

        $this->protokoll->record(
            ereignis: AuditEvent::OperatorRoleChanged,
            gegenstand: $betreiber,
            kontext: ['von' => $vorher?->value, 'nach' => $nachher->value],
            ohneOrganisation: true,
        );

        return back()->with('erfolg', "Rolle geändert: {$nachher->label()}.");
    }

    public function deaktivieren(Request $request, User $betreiber): RedirectResponse
    {
        $this->nurBetreiber($betreiber);

        $request->validate(BackofficeController::PASSWORT);

        if ($betreiber->is($request->user()) || $betreiber->istLetzterSuperAdmin()) {
            throw ValidationException::withMessages([
                'betreiber' => $betreiber->istLetzterSuperAdmin()
                    ? 'Das ist der letzte aktive Super-Admin. Ernennen Sie zuerst einen weiteren.'
                    : 'Das eigene Konto deaktiviert jemand anderes.',
            ]);
        }

        // EnsureUserIsActive wirft die Person mit der naechsten Anfrage
        // hinaus -- nicht erst, wenn ihre Sitzung ablaeuft.
        $betreiber->deactivated_at = now()->toImmutable();
        $betreiber->save();

        $this->protokoll->record(
            ereignis: AuditEvent::OperatorDeactivated,
            gegenstand: $betreiber,
            ohneOrganisation: true,
        );

        return back()->with('erfolg', 'Konto deaktiviert.');
    }

    public function reaktivieren(Request $request, User $betreiber): RedirectResponse
    {
        $this->nurBetreiber($betreiber);

        $request->validate(BackofficeController::PASSWORT);

        $betreiber->deactivated_at = null;
        $betreiber->save();

        $this->protokoll->record(
            ereignis: AuditEvent::OperatorReactivated,
            gegenstand: $betreiber,
            ohneOrganisation: true,
        );

        return back()->with('erfolg', 'Konto reaktiviert.');
    }

    /**
     * Loescht ein Betreiberkonto endgueltig (Nachtrag 28.09.2026). Dieselben
     * Grenzen wie beim Deaktivieren -- nur ohne Weg zurueck.
     */
    public function loeschen(Request $request, User $betreiber): RedirectResponse
    {
        $this->nurBetreiber($betreiber);

        $request->validate(BackofficeController::PASSWORT);

        if ($betreiber->is($request->user()) || $betreiber->istLetzterSuperAdmin()) {
            throw ValidationException::withMessages([
                'betreiber' => $betreiber->istLetzterSuperAdmin()
                    ? 'Das ist der letzte aktive Super-Admin. Ernennen Sie zuerst einen weiteren.'
                    : 'Das eigene Konto löscht jemand anderes.',
            ]);
        }

        $this->konten->loesche($betreiber);

        return back()->with('erfolg', 'Konto gelöscht.');
    }

    /**
     * Setzt den zweiten Faktor eines anderen Betreibers zurueck (WP-35). Den
     * eigenen aendert jede Person unter Einstellungen; den des letzten
     * Super-Admins, der nicht mehr hineinkommt, die Konsole.
     */
    public function zweiFaktorZuruecksetzen(Request $request, User $betreiber, ZweiterFaktor $zweiterFaktor): RedirectResponse
    {
        $this->nurBetreiber($betreiber);

        $request->validate(BackofficeController::PASSWORT);

        if ($betreiber->is($request->user())) {
            throw ValidationException::withMessages([
                'betreiber' => 'Den eigenen zweiten Faktor ändern Sie unter Einstellungen.',
            ]);
        }

        if ($betreiber->hatZweiFaktor()) {
            $zweiterFaktor->setzeZurueck($betreiber);
        }

        return back()->with('erfolg', 'Zweiter Faktor zurückgesetzt. Die Person meldet sich jetzt nur mit Passwort an.');
    }

    /** Diese Seite verwaltet Betreiberkonten -- das Team einer Praxis verwaltet die Praxis. */
    private function nurBetreiber(User $konto): void
    {
        abort_unless($konto->istBetreiber(), 404);
    }
}
