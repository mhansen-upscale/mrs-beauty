<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Audit\ImpersonationContext;
use App\Enums\Ability;
use App\Enums\AuditEvent;
use App\Enums\ImpersonationMode;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travel;

/*
|--------------------------------------------------------------------------
| WP-05, Abnahmekriterien 11 bis 19
|--------------------------------------------------------------------------
*/

/**
 * Die protokollierten Ereignisse als Werte.
 *
 * `event` ist auf ein Enum gecastet -- pluck() liefert also Enum-Faelle, und
 * die lassen sich nicht in Zeichenketten umwandeln.
 *
 * @return list<string>
 */
function ereignisse(): array
{
    /** @var list<string> */
    return AuditLog::query()
        ->withoutGlobalScopes()
        ->get()
        ->map(fn (AuditLog $eintrag): string => $eintrag->event->value)
        ->values()
        ->all();
}

it('laesst niemanden ohne Super-Admin-Recht impersonieren', function (): void {
    $organisation = alsMandant();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    app(Impersonation::class)->start($inhaberin, $organisation, 'Ich will halt');
})->throws(RuntimeException::class, 'Super-Admin');

it('startet jede Sitzung maskiert', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');

    expect($sitzung->mode)->toBe(ImpersonationMode::Masked)
        ->and($sitzung->approved_at)->toBeNull()
        ->and($sitzung->laeuft())->toBeTrue()
        ->and($sitzung->hatVollzugriff())->toBeFalse();
});

it('verlangt eine Begruendung', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    app(Impersonation::class)->start($superAdmin, $organisation, '  ');
})->throws(InvalidArgumentException::class);

it('ersetzt personenbezogene Werte im maskierten Modus', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->create([
        'name' => 'Dr. Martina Geheim',
        'email' => 'martina@sehr-privat.de',
    ]);

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');
    app(ImpersonationContext::class)->set($sitzung);

    $serialisiert = $mitglied->toArray();

    expect($serialisiert['name'])->not->toBe('Dr. Martina Geheim')
        ->and($serialisiert['name'])->toStartWith('Maskiert ')
        ->and($serialisiert['email'])->not->toBe('martina@sehr-privat.de')
        ->and($serialisiert['email'])->toEndWith('@maskiert.invalid');
});

it('gibt zwei Datensaetzen unterscheidbare Platzhalter', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    $eine = User::factory()->fuer($organisation, Role::Reception)->create(['name' => 'Anna']);
    $andere = User::factory()->fuer($organisation, Role::Reception)->create(['name' => 'Berta']);

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');
    app(ImpersonationContext::class)->set($sitzung);

    // Ohne Unterscheidbarkeit kann eine Supportkraft nicht sagen, welche Zeile
    // sie meint.
    expect($eine->name)->not->toBe($andere->name);
});

it('laesst ein maskiertes Feld nicht ueberschreiben', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');
    app(ImpersonationContext::class)->set($sitzung);

    // Sonst landet der Platzhalter in der Datenbank, weil ein Formular ihn
    // brav zurueckgeschickt hat.
    $mitglied->name = 'Ueberschrieben';
})->throws(RuntimeException::class, 'nicht aenderbar');

it('zeigt ohne Impersonation die echten Werte', function (): void {
    $organisation = alsMandant();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->create(['name' => 'Dr. Martina Geheim']);

    expect($mitglied->toArray()['name'])->toBe('Dr. Martina Geheim');
});

it('gibt ohne Freigabe keinen Vollzugriff', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');

    // Selbst wer den Modus von Hand setzt, bekommt ohne Freigabe nichts.
    $sitzung->mode = ImpersonationMode::Full;

    app(ImpersonationContext::class)->set($sitzung);

    expect($sitzung->hatVollzugriff())->toBeFalse()
        ->and(app(ImpersonationContext::class)->masks())->toBeTrue();
});

it('laesst nur eine Inhaberin des betroffenen Mandanten freigeben', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');

    app(Impersonation::class)->approve($sitzung, $empfang);
})->throws(RuntimeException::class, 'Inhaberin');

it('laesst eine fremde Inhaberin nicht freigeben', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    $fremde = organisation('Andere Praxis');
    $fremdeInhaberin = User::factory()->fuer($fremde, Role::Owner)->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');

    app(Impersonation::class)->approve($sitzung, $fremdeInhaberin);
})->throws(RuntimeException::class, 'betroffene Mandant');

it('hebt die Maskierung nach der Freigabe auf', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->create(['name' => 'Dr. Martina Geheim']);

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');
    $sitzung = app(Impersonation::class)->approve($sitzung, $inhaberin);

    app(ImpersonationContext::class)->set($sitzung);

    expect($sitzung->hatVollzugriff())->toBeTrue()
        ->and($mitglied->toArray()['name'])->toBe('Dr. Martina Geheim');
});

it('macht eine abgelaufene Sitzung sofort wirkungslos', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $mitglied = User::factory()->fuer($organisation, Role::Reception)->create(['name' => 'Dr. Martina Geheim']);

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');
    $sitzung = app(Impersonation::class)->approve($sitzung, $inhaberin);

    // Kein Aufraeumjob, nur die Uhr.
    travel(2)->hours();

    app(ImpersonationContext::class)->set($sitzung);

    expect($sitzung->laeuft())->toBeFalse()
        ->and($sitzung->istAbgelaufen())->toBeTrue()
        ->and($sitzung->hatVollzugriff())->toBeFalse()
        // Und die Maskierung greift wieder.
        ->and($mitglied->toArray()['name'])->not->toBe('Dr. Martina Geheim');
});

it('laesst je Super-Admin nur eine laufende Sitzung zu', function (): void {
    $eine = alsMandant();
    $andere = organisation('Andere Praxis');
    $superAdmin = User::factory()->superAdmin()->create();

    $erste = app(Impersonation::class)->start($superAdmin, $eine, 'Ticket 4711, Termin fehlt');
    $zweite = app(Impersonation::class)->start($superAdmin, $andere, 'Ticket 4712, Kalender haengt');

    expect($erste->refresh()->ended_at)->not->toBeNull()
        ->and($erste->ended_reason)->toBe('superseded')
        ->and($zweite->laeuft())->toBeTrue();
});

it('protokolliert Start, Freigabe und Ende', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');
    $sitzung = app(Impersonation::class)->approve($sitzung, $inhaberin);
    app(Impersonation::class)->end($sitzung, 'manual');

    expect(ereignisse())
        ->toContain(AuditEvent::ImpersonationStarted->value)
        ->toContain(AuditEvent::ImpersonationApproved->value)
        ->toContain(AuditEvent::ImpersonationEnded->value);
});

it('protokolliert den Ablauf einer Sitzung', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');
    app(Impersonation::class)->end($sitzung, 'expired');

    expect(ereignisse())->toContain(AuditEvent::ImpersonationExpired->value);
});

it('zeigt die Vorgaenge im Protokoll des betroffenen Mandanten', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');

    // Der Global Scope des Mandanten greift -- der Eintrag muss also seine
    // organization_id tragen, sonst saehe die Praxis ihn nie.
    app(TenantContext::class)->set($organisation);

    expect(AuditLog::query()->where('event', AuditEvent::ImpersonationStarted->value)->count())->toBe(1);
});

it('gibt dem Support die Sicht einer Inhaberin', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');

    actingAs($superAdmin)
        ->withSession(['impersonation_session_id' => $sitzung->uuid])
        ->get(route('team.index'))
        ->assertOk();
});

it('laesst den Support seinen eigenen Vollzugriff nicht freigeben', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');

    // Der Kern von Entscheidung C4: die Freigabe kommt vom Kunden, nie vom
    // Support selbst. Ohne diese Ausnahme waere die ganze Konstruktion hohl.
    actingAs($superAdmin)
        ->withSession(['impersonation_session_id' => $sitzung->uuid])
        ->post(route('impersonation.approve', ['session' => $sitzung->uuid]))
        ->assertForbidden();

    expect($sitzung->refresh()->hatVollzugriff())->toBeFalse();
});

it('laesst den Support nicht an das Abo', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');
    app(ImpersonationContext::class)->set($sitzung);

    expect($superAdmin->hasAbility(Ability::ManageBilling))->toBeFalse()
        ->and($superAdmin->hasAbility(Ability::ManageTeam))->toBeTrue();
});

it('macht die Impersonation in jeder Antwort erkennbar', function (): void {
    $organisation = alsMandant();
    $superAdmin = User::factory()->superAdmin()->create();

    $sitzung = app(Impersonation::class)->start($superAdmin, $organisation, 'Ticket 4711, Termin fehlt');

    actingAs($superAdmin)
        ->withSession(['impersonation_session_id' => $sitzung->uuid])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->where('impersonation.mode', ImpersonationMode::Masked->value)
            ->where('impersonation.masked', true)
            ->where('impersonation.reason', 'Ticket 4711, Termin fehlt')
        );
});
