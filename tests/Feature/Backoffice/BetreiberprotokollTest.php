<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-34a, Abnahmekriterien 19 bis 22 -- das Protokoll des Betreibers
|--------------------------------------------------------------------------
|
| **Ein Protokoll, in das jeder Seitenaufruf schreibt, ist keines.** Bis hier
| schrieb jede Anfrage eines Betreibers einen Querzugriff -- und die echten
| gingen darin unter.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function querzugriffe(): int
{
    return AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::CrossTenantAccess->value)->count();
}

it('schreibt waehrend einer Impersonation keinen Querzugriff je Seitenaufruf', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $betreiber = User::factory()->superAdmin()->create();
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Ticket 4711, Termin fehlt');
    ohneMandant();

    $vorher = querzugriffe();

    actingAs($betreiber)->withSession(impersonationSitzung($sitzung))->get(route('team.index'))->assertOk();
    actingAs($betreiber)->withSession(impersonationSitzung($sitzung))->get(route('dashboard'))->assertOk();

    expect(querzugriffe())->toBe($vorher);
});

it('wendet die Impersonation nur mit der eigenen Sitzung an', function (): void {
    // Die Sitzung gehoert dem Betreiber, der sie gestartet hat -- nicht
    // jedem, der ihre Kennung in die Session legt.
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $sitzung = app(Impersonation::class)->start(User::factory()->superAdmin()->create(), $praxis, 'Ticket 4711, Termin fehlt');
    ohneMandant();

    actingAs(User::factory()->superAdmin()->create())
        ->withSession(impersonationSitzung($sitzung))
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->component('DashboardBetreiber')->where('impersonation', null));
});

it('beendet eine laufende Impersonation beim Abmelden', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $betreiber = User::factory()->superAdmin()->create();
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Ticket 4711, Termin fehlt');
    ohneMandant();

    actingAs($betreiber)->withSession(impersonationSitzung($sitzung))->post(route('logout'));

    $beendet = app(TenantContext::class)->runAs($praxis, fn (): ?ImpersonationSession => ImpersonationSession::query()->first());

    // Sonst stuende in der Praxis bis zum Ablauf "Support hat Zugriff".
    expect($beendet?->ended_at)->not->toBeNull()
        ->and($beendet?->ended_reason)->toBe('logout')
        ->and(AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::ImpersonationEnded->value)->count())->toBe(1);
});

it('zeigt im Betreiberprotokoll Querzugriffe, Anmeldungen und Handlungen an Praxen', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    ohneMandant();

    $betreiber = User::factory()->superAdmin()->create();

    // Eine Handlung an einer Praxis -- sie steht im Protokoll der Praxis.
    actingAs($betreiber)->post(route('backoffice.sperren', ['organisation' => $praxis->uuid]), [
        'grund' => 'Zahlungsausfall nach dritter Mahnung',
        'current_password' => 'password',
    ]);

    // Ein Vorgang der Praxis selbst gehoert nicht hierher.
    alsMandant($praxis);
    actingAs($empfang);
    Contact::create(['first_name' => 'Eigen', 'last_name' => 'Sache']);
    ohneMandant();
    auth()->guard('web')->logout();

    actingAs($betreiber)
        ->get(route('backoffice.protokoll'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('backoffice/Protokoll')
            ->where('eintraege', fn (Collection $eintraege): bool => $eintraege->contains(
                fn (array $eintrag): bool => $eintrag['ereignis'] === AuditEvent::TenantSuspended->value
                    && $eintrag['praxis'] === 'Demo-Praxis'
                    && $eintrag['begruendung'] === 'Zahlungsausfall nach dritter Mahnung'
            ))
            ->where('eintraege', fn (Collection $eintraege): bool => $eintraege->contains(
                fn (array $eintrag): bool => $eintrag['ereignis'] === AuditEvent::CrossTenantAccess->value
            ))
            ->where('eintraege', fn (Collection $eintraege): bool => ! $eintraege->contains(
                fn (array $eintrag): bool => $eintrag['ereignis'] === AuditEvent::Created->value && $eintrag['praxis'] === 'Demo-Praxis'
            ))
        );
});

it('filtert das Betreiberprotokoll nach Ereignis und Betreiber', function (): void {
    $praxis = organisation('Demo-Praxis');
    $eine = User::factory()->superAdmin()->create(['name' => 'Eine']);
    $andere = User::factory()->superAdmin()->create(['name' => 'Andere']);

    actingAs($eine)->post(route('backoffice.sperren', ['organisation' => $praxis->uuid]), ['grund' => 'Gesperrt von Eine', 'current_password' => 'password']);
    actingAs($andere)->post(route('backoffice.entsperren', ['organisation' => $praxis->uuid]), ['grund' => 'Entsperrt von Andere', 'current_password' => 'password']);

    actingAs($eine)
        ->get(route('backoffice.protokoll', ['ereignis' => AuditEvent::TenantSuspended->value]))
        ->assertInertia(fn ($seite) => $seite
            ->has('eintraege', 1)
            ->where('eintraege.0.begruendung', 'Gesperrt von Eine')
        );

    actingAs($eine)
        ->get(route('backoffice.protokoll', ['betreiber' => $andere->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('eintraege', fn (Collection $eintraege): bool => $eintraege->every(fn (array $eintrag): bool => $eintrag['handelnde'] === 'Andere'))
        );
});

it('laesst Customer Success nicht ins Betreiberprotokoll', function (): void {
    actingAs(User::factory()->customerSuccess()->create())
        ->get(route('backoffice.protokoll'))
        ->assertForbidden();
});

it('loescht Eintraege ohne Organisation nach 36 Monaten', function (): void {
    $jetzt = CarbonImmutable::now();
    $mandant = app(TenantContext::class);

    // Das Protokoll laesst sich nicht aendern -- alte Eintraege entstehen
    // deshalb in der Vergangenheit.
    travelTo($jetzt->subMonths((int) config('mrs.audit.retention_months') + 1));
    $mandant->acrossTenants('Alter Querzugriff fuer die Aufbewahrung', fn () => null);

    travelTo($jetzt->subMonths(1));
    $mandant->acrossTenants('Junger Querzugriff fuer die Aufbewahrung', fn () => null);

    travelTo($jetzt);

    expect(Artisan::call('mrs:aufbewahrung', ['--scharf' => true]))->toBe(0);

    $gruende = AuditLog::query()->withoutGlobalScopes()->whereNull('organization_id')->pluck('reason');

    expect($gruende)->not->toContain('Alter Querzugriff fuer die Aufbewahrung')
        ->and($gruende)->toContain('Junger Querzugriff fuer die Aufbewahrung');
});
