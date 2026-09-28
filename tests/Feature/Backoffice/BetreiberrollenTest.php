<?php

declare(strict_types=1);

use App\Audit\Impersonation;
use App\Enums\OperatorAbility;
use App\Enums\OperatorRole;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Routen;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-34a, Abnahmekriterien 6 bis 9 -- die Betreiberrollen
|--------------------------------------------------------------------------
|
| **Ein Betreiber gehoert zu keiner Praxis. Was er darf, haengt an seiner
| Betreiberrolle, nie an einer Praxisrolle** (Entscheidung C14).
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Jede Route des Backoffice mit der Faehigkeit, die sie verlangt.
 *
 * **Die Tabelle ist der Test.** Eine neue Route, die hier fehlt, laesst den
 * Test unten scheitern -- sonst kaeme sie ohne Pruefung hinzu.
 *
 * @return array<string, array{methode: string, parameter: array<string, string>, faehigkeit: OperatorAbility}>
 */
function betreiberrouten(Organization $praxis, User $anderer): array
{
    $mandant = ['organisation' => (string) $praxis->uuid];
    $konto = ['betreiber' => (string) $anderer->uuid];

    return [
        'backoffice.index' => ['methode' => 'get', 'parameter' => [], 'faehigkeit' => OperatorAbility::MandantenSehen],
        'backoffice.show' => ['methode' => 'get', 'parameter' => $mandant, 'faehigkeit' => OperatorAbility::MandantenSehen],
        'backoffice.sperren' => ['methode' => 'post', 'parameter' => $mandant, 'faehigkeit' => OperatorAbility::MandantenSperren],
        'backoffice.entsperren' => ['methode' => 'post', 'parameter' => $mandant, 'faehigkeit' => OperatorAbility::MandantenSperren],
        'backoffice.gutschrift' => ['methode' => 'post', 'parameter' => $mandant, 'faehigkeit' => OperatorAbility::KontingentGutschreiben],
        'backoffice.abo' => ['methode' => 'post', 'parameter' => $mandant, 'faehigkeit' => OperatorAbility::AboEingreifen],
        'backoffice.testphase' => ['methode' => 'post', 'parameter' => $mandant, 'faehigkeit' => OperatorAbility::TestphaseVerlaengern],
        'backoffice.paket' => ['methode' => 'get', 'parameter' => [], 'faehigkeit' => OperatorAbility::PaketVerwalten],
        'backoffice.paket.store' => ['methode' => 'post', 'parameter' => [], 'faehigkeit' => OperatorAbility::PaketVerwalten],
        'backoffice.betreiber.index' => ['methode' => 'get', 'parameter' => [], 'faehigkeit' => OperatorAbility::BetreiberVerwalten],
        'backoffice.betreiber.store' => ['methode' => 'post', 'parameter' => [], 'faehigkeit' => OperatorAbility::BetreiberVerwalten],
        'backoffice.betreiber.rolle' => ['methode' => 'patch', 'parameter' => $konto, 'faehigkeit' => OperatorAbility::BetreiberVerwalten],
        'backoffice.betreiber.deaktivieren' => ['methode' => 'post', 'parameter' => $konto, 'faehigkeit' => OperatorAbility::BetreiberVerwalten],
        'backoffice.betreiber.reaktivieren' => ['methode' => 'post', 'parameter' => $konto, 'faehigkeit' => OperatorAbility::BetreiberVerwalten],
        'backoffice.betreiber.zwei-faktor' => ['methode' => 'post', 'parameter' => $konto, 'faehigkeit' => OperatorAbility::BetreiberVerwalten],
        'backoffice.protokoll' => ['methode' => 'get', 'parameter' => [], 'faehigkeit' => OperatorAbility::ProtokollSehen],
        'impersonation.store' => ['methode' => 'post', 'parameter' => [], 'faehigkeit' => OperatorAbility::SupportZugriff],
        'impersonation.destroy' => ['methode' => 'delete', 'parameter' => [], 'faehigkeit' => OperatorAbility::SupportZugriff],
    ];
}

it('verlangt auf jeder Route die Faehigkeit der Tabelle', function (OperatorRole $rolle): void {
    $praxis = organisation('Demo-Praxis');
    $anderer = User::factory()->betreiber(OperatorRole::CustomerSuccess)->create();
    $betreiber = User::factory()->betreiber($rolle)->create();

    foreach (betreiberrouten($praxis, $anderer) as $name => $route) {
        $antwort = actingAs($betreiber)->{$route['methode']}(route($name, $route['parameter']));

        // Erlaubt heisst nicht "erfolgreich" -- ohne Formulardaten kommt eine
        // Validierung zurueck. Es heisst: nicht an der Tuer abgewiesen.
        if ($rolle->allows($route['faehigkeit'])) {
            expect($antwort->status())->not->toBe(403, "{$rolle->value} wird an {$name} abgewiesen, darf aber.");
        } else {
            expect($antwort->status())->toBe(403, "{$rolle->value} kommt an {$name} vorbei, darf aber nicht.");
        }
    }
})->with(fn (): array => OperatorRole::cases());

it('kennt jede Route des Backoffice', function (): void {
    $bekannt = array_keys(betreiberrouten(organisation(), User::factory()->betreiber(OperatorRole::Finanzen)->create()));

    $vorhanden = collect(Routen::getRoutes()->getRoutes())
        ->map(fn (Route $route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => is_string($name) && str_starts_with($name, 'backoffice.'))
        // Die Anmeldung ist die eine Route ohne Faehigkeit -- vor ihr ist
        // noch niemand angemeldet.
        ->reject(fn (string $name): bool => str_starts_with($name, 'backoffice.anmelden'))
        ->values()
        ->all();

    expect(array_diff($vorhanden, $bekannt))->toBeEmpty('Ohne Eintrag in betreiberrouten(): '.implode(', ', array_diff($vorhanden, $bekannt)));
});

it('laesst Customer Success nicht sperren und Finanzen nicht in eine Praxis', function (): void {
    $praxis = organisation('Demo-Praxis');

    actingAs(User::factory()->customerSuccess()->create())
        ->post(route('backoffice.sperren', ['organisation' => $praxis->uuid]), ['grund' => 'Zahlungsausfall', 'current_password' => 'password'])
        ->assertForbidden();

    actingAs(User::factory()->finanzen()->create())
        ->post(route('impersonation.store'), ['organization' => $praxis->uuid, 'reason' => 'Nur mal schauen, Ticket 4711'])
        ->assertForbidden();

    expect($praxis->fresh()?->suspended_at)->toBeNull();
});

it('laesst Finanzen auch mechanisch nicht impersonieren', function (): void {
    // Die Route ist die eine Tuer, die Mechanik die zweite (WP-05).
    $praxis = alsMandant(organisation('Demo-Praxis'));

    app(Impersonation::class)->start(User::factory()->finanzen()->create(), $praxis, 'Nur mal schauen, Ticket 4711');
})->throws(RuntimeException::class, 'Super-Admin');

it('weist einen Betreiber mit Praxis in der Datenbank ab', function (): void {
    $praxis = organisation('Demo-Praxis');

    // Ueber Eloquent ...
    expect(fn () => User::factory()->superAdmin()->create(['organization_id' => $praxis->getKey()]))
        ->toThrow(QueryException::class);

    // ... und am Modell vorbei.
    $mitglied = User::factory()->fuer($praxis, Role::Owner)->create();

    expect(fn () => DB::table('users')->where('id', $mitglied->getKey())->update(['operator_role' => OperatorRole::SuperAdmin->value]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('users')->where('id', $mitglied->getKey())->update(['operator_role' => OperatorRole::SuperAdmin->value, 'role' => null, 'organization_id' => null]))
        ->not->toThrow(QueryException::class);
});

it('laesst eine Praxisinhaberin in keine Route des Backoffice', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    // Auch nicht mit der Sitzung eines Betreibers in der Session: die Rolle
    // haengt am Konto, nicht an dem, was der Browser mitschickt.
    $sitzung = app(Impersonation::class)->start(User::factory()->superAdmin()->create(), $praxis, 'Ticket 4711, Termin fehlt');

    ohneMandant();

    foreach (betreiberrouten($praxis, User::factory()->customerSuccess()->create()) as $name => $route) {
        $antwort = actingAs($inhaberin)
            ->withSession(impersonationSitzung($sitzung))
            ->{$route['methode']}(route($name, $route['parameter']));

        expect($antwort->status())->toBe(403, "Die Inhaberin kommt an {$name} vorbei.");
    }
});

it('zeigt Umsatz und Modellkosten nur, wer die Finanzen sehen darf', function (): void {
    alsMandant(organisation('Nord'));
    ohneMandant();

    actingAs(User::factory()->customerSuccess()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->component('DashboardBetreiber')
            ->where('kennzahlen.abos.mrrCent', null)
            ->where('kennzahlen.monat.modellkostenUsdCent', null)
            // Die Zustaende der Abos sieht Customer Success sehr wohl.
            ->where('kennzahlen.abos.testphase', 1)
        );

    actingAs(User::factory()->finanzen()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->where('kennzahlen.abos.mrrCent', 0)
            ->where('kennzahlen.monat.modellkostenUsdCent', 0)
        );
});

it('teilt der Oberflaeche Rolle und Faehigkeiten des Betreibers mit', function (): void {
    actingAs(User::factory()->customerSuccess()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->where('auth.betreiber.rolle', OperatorRole::CustomerSuccess->value)
            ->where('auth.betreiber.faehigkeiten', array_map(
                fn (OperatorAbility $faehigkeit): string => $faehigkeit->value,
                OperatorRole::CustomerSuccess->abilities(),
            ))
        );

    $praxis = alsMandant(organisation('Demo-Praxis'));

    actingAs(User::factory()->fuer($praxis, Role::Owner)->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('auth.betreiber', null));
});
