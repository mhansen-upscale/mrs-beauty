<?php

declare(strict_types=1);

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| WP-05, Abnahmekriterien 1 bis 10
|--------------------------------------------------------------------------
*/

/**
 * Liest Protokolleintraege am Global Scope vorbei.
 *
 * Bewusst ueber withoutGlobalScopes() und nicht ueber acrossTenants(): der
 * benannte Kanal protokolliert sich selbst und wuerde jeden Testlauf
 * verfaelschen. Im Anwendungscode ist withoutGlobalScopes() untersagt --
 * durchgesetzt durch tests/Feature/Audit/DeckungTest.php.
 *
 * @return Collection<int, AuditLog>
 */
function protokoll(): Collection
{
    return AuditLog::query()->withoutGlobalScopes()->orderBy('occurred_at')->get();
}

/** Der juengste Eintrag. Fehlt er, ist der Test ohnehin kaputt. */
function letzterEintrag(): AuditLog
{
    return protokoll()->last() ?? throw new RuntimeException('Kein Protokolleintrag vorhanden.');
}

/** Der erste Eintrag zu einem Ereignis. */
function eintragZu(AuditEvent $ereignis): AuditLog
{
    return protokoll()->first(fn (AuditLog $e): bool => $e->event === $ereignis)
        ?? throw new RuntimeException("Kein Eintrag zu {$ereignis->value}.");
}

// --- Append-only -----------------------------------------------------------

it('laesst einen Protokolleintrag ueber Eloquent nicht aendern', function (): void {
    alsMandant();
    User::factory()->create();

    $eintrag = letzterEintrag();

    $eintrag->reason = 'nachtraeglich';
    $eintrag->save();
})->throws(RuntimeException::class, 'append-only');

it('laesst einen Protokolleintrag ueber Eloquent nicht loeschen', function (): void {
    alsMandant();
    User::factory()->create();

    letzterEintrag()->delete();
})->throws(RuntimeException::class, 'append-only');

it('weist ein UPDATE direkt auf der Datenbank ab', function (): void {
    alsMandant();
    User::factory()->create();

    // An Eloquent vorbei -- genau der Fall, gegen den die Sperre im Modell
    // nicht hilft.
    DB::table('audit_logs')->update(['reason' => 'manipuliert']);
})->throws(QueryException::class);

it('weist ein DELETE direkt auf der Datenbank ab', function (): void {
    alsMandant();
    User::factory()->create();

    DB::table('audit_logs')->delete();
})->throws(QueryException::class);

it('laesst den Aufbewahrungsjob ueber seinen benannten Kanal loeschen', function (): void {
    alsMandant();
    User::factory()->create();

    expect(protokoll())->not->toBeEmpty();

    // Entscheidung C7 verlangt eine Aufbewahrung von 36 Monaten. Ohne diesen
    // Kanal waere sie nicht umsetzbar.
    DB::statement('SET @mrs_audit_retention = 1');
    DB::table('audit_logs')->delete();
    DB::statement('SET @mrs_audit_retention = NULL');

    expect(protokoll())->toBeEmpty();
});

// --- Keine Klartext-Personendaten ------------------------------------------

it('nennt geaenderte Felder, aber nicht deren Werte', function (): void {
    alsMandant();

    $benutzer = User::factory()->create(['name' => 'Dr. Martina Geheim']);

    $eintrag = letzterEintrag();

    expect($eintrag->changed_fields)->toContain('name', 'email')
        ->and($eintrag->context)->not->toHaveKey('name')
        ->and($eintrag->context)->not->toHaveKey('email');
});

it('laesst als unbedenklich erklaerte Felder ihren Wert mitfuehren', function (): void {
    $organisation = alsMandant();
    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    $benutzer->role = Role::Admin;
    $benutzer->save();

    $eintrag = letzterEintrag();

    expect($eintrag->event)->toBe(AuditEvent::Updated)
        ->and($eintrag->changed_fields)->toContain('role')
        ->and($eintrag->context)->toHaveKey('role')
        ->and(($eintrag->context ?? [])['role'] ?? null)->toBe('admin');
});

it('schreibt keinen der gesetzten Klartextwerte ins Protokoll', function (): void {
    $organisation = alsMandant();

    $geheimnisse = ['Dr. Martina Geheim', 'martina.geheim@sehr-privat.de'];

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create([
        'name' => $geheimnisse[0],
        'email' => $geheimnisse[1],
    ]);

    $benutzer->name = 'Dr. Martina Anders';
    $benutzer->save();

    // Der ganze Protokollinhalt als Zeichenkette -- wenn irgendwo ein
    // Klartextwert durchrutscht, faellt er hier auf.
    $roh = (string) json_encode(
        DB::table('audit_logs')->get(['event', 'actor_label', 'changed_fields', 'context', 'reason'])
    );

    foreach ([...$geheimnisse, 'Dr. Martina Anders'] as $geheimnis) {
        expect($roh)->not->toContain($geheimnis);
    }
});

// --- Mandantengrenze -------------------------------------------------------

it('zeigt in der Protokollansicht nur die eigene Organisation', function (): void {
    $eigene = alsMandant();
    $inhaberin = User::factory()->fuer($eigene, Role::Owner)->create();

    $fremde = organisation('Andere Praxis');
    User::factory()->fuer($fremde, Role::Owner)->create();

    $eigeneEintraege = protokoll()
        ->filter(fn (AuditLog $e): bool => $e->getAttribute('organization_id') === $eigene->getKey())
        ->count();

    expect(protokoll()->count())->toBeGreaterThan($eigeneEintraege);

    actingAs($inhaberin)
        ->get(route('audit.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->has('entries', $eigeneEintraege));
});

it('protokolliert jeden Zugriff quer zu den Mandanten mit Begruendung', function (): void {
    alsMandant();

    app(TenantContext::class)->acrossTenants(
        'Wartungsfall 4711',
        fn (): int => User::query()->count()
    );

    $eintrag = eintragZu(AuditEvent::CrossTenantAccess);

    expect($eintrag->reason)->toBe('Wartungsfall 4711')
        // Ein mandantenuebergreifender Vorgang gehoert zu keiner Organisation.
        ->and($eintrag->getAttribute('organization_id'))->toBeNull();
});

it('laesst einen Zugriff quer zu den Mandanten ohne Begruendung nicht zu', function (): void {
    alsMandant();

    app(TenantContext::class)->acrossTenants('   ', fn (): int => 1);
})->throws(InvalidArgumentException::class);

it('verhindert, dass das Protokoll sich selbst protokolliert', function (): void {
    alsMandant();

    app(AuditLogger::class)->record(AuditEvent::Created);

    // Genau ein Eintrag: der Protokolleintrag selbst loest keinen weiteren aus.
    expect(protokoll())->toHaveCount(1);
});
