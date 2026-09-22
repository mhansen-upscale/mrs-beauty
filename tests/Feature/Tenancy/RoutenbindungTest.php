<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveTenant;
use App\Models\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Routen;

use function Pest\Laravel\actingAs;

use Tests\Feature\Verfuegbarkeit\Aufbau;

/*
|--------------------------------------------------------------------------
| Der Mandant steht, bevor eine Routenbindung aufgeloest wird
|--------------------------------------------------------------------------
|
| Laravel sortiert die Middleware einer Route nach einer Prioritaetsliste, und
| SubstituteBindings steht darin. Eigene Middleware, die nicht in der Liste
| steht, landet dadurch **hinter** der Bindungsaufloesung -- unabhaengig
| davon, in welcher Reihenfolge sie in bootstrap/app.php notiert ist.
|
| Ein Route-Model-Binding auf ein Mandantenmodell lief damit ohne Mandanten
| und warf TenantContextMissing, bevor die Aufloesung an der Reihe war. Die
| bestehenden Tests haben das nicht gesehen: alsMandant() setzt das Singleton
| fuer den ganzen Testlauf, der Mandant stand dort also schon vor der Anfrage.
| Diese Datei vergisst ihn deshalb ausdruecklich.
|
*/

it('loest den Mandanten vor jeder Routenbindung auf', function (): void {
    $verstoesse = [];
    $geprueft = 0;

    foreach (Routen::getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        $reihenfolge = array_values(array_filter(
            app('router')->gatherRouteMiddleware($route),
            fn (mixed $eintrag): bool => is_string($eintrag)
        ));

        $bindungen = array_search(SubstituteBindings::class, $reihenfolge, true);
        $mandant = array_search(ResolveTenant::class, $reihenfolge, true);

        if ($bindungen === false || $mandant === false) {
            continue;
        }

        $geprueft++;

        if ($mandant > $bindungen) {
            $verstoesse[] = $route->uri();
        }
    }

    // Nicht leer greifen: es muss Routen geben, die beides tragen.
    expect($geprueft)->toBeGreaterThan(0, 'Keine Route traegt beide Middleware -- der Test prueft nichts.')
        ->and($verstoesse)->toBeEmpty(
            'Diese Routen loesen ihre Bindungen ohne Mandanten auf: '.implode(', ', $verstoesse)
        );
});

it('ordnet die eigene Middleware vollstaendig vor die Bindungsaufloesung', function (): void {
    // Die Gegenprobe zum Test darueber: er greift ins Leere, wenn keine Route
    // beide Middleware traegt. Diese hier nennt die erwartete Kette.
    $route = collect(Routen::getRoutes()->getRoutes())
        ->first(fn (Route $r): bool => $r->getName() === 'kalender.google.verbinden');

    expect($route)->not->toBeNull();

    /** @var Route $route */
    $reihenfolge = array_values(array_filter(
        app('router')->gatherRouteMiddleware($route),
        fn (mixed $eintrag): bool => is_string($eintrag)
    ));

    // Fehlt eine der vier, ist die Kette nicht nur falsch sortiert, sondern
    // gar nicht da -- das soll der Test sagen und nicht an einem Vergleich
    // gegen false scheitern.
    $stelle = function (string $klasse) use ($reihenfolge): int {
        $index = array_search($klasse, $reihenfolge, true);

        expect($index)->not->toBeFalse("{$klasse} fehlt in der Middleware dieser Route.");

        return (int) $index;
    };

    expect($stelle(EnsureUserIsActive::class))->toBeLessThan($stelle(ResolveTenant::class))
        ->and($stelle(ResolveTenant::class))->toBeLessThan($stelle(ApplyImpersonation::class))
        ->and($stelle(ApplyImpersonation::class))->toBeLessThan($stelle(SubstituteBindings::class));
});

it('loest ein gebundenes Mandantenmodell ohne vorher gesetzten Kontext auf', function (): void {
    $organisation = alsMandant();
    $aufbau = new Aufbau;
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    // **So kommt eine echte Anfrage an**: ohne Mandanten. Ihn setzt die
    // Middleware aus dem angemeldeten Benutzer -- und sie muss das tun,
    // bevor die Bindung aufgeloest wird.
    ohneMandant();

    actingAs($benutzer)
        ->get(route('kalender.google.verbinden', ['practitioner' => $aufbau->behandler->uuid]))
        ->assertRedirect();
});

it('loest auch die Bindungen der Stammdaten ohne vorher gesetzten Kontext auf', function (): void {
    // Nicht nur der Kalender: derselbe Fehler lag auf jeder Route mit
    // gebundenem Mandantenmodell, also seit WP-08.
    $organisation = alsMandant();
    $aufbau = new Aufbau;
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    ohneMandant();

    actingAs($benutzer)
        ->delete(route('locations.deactivate', ['location' => $aufbau->standort->uuid]))
        ->assertRedirect();

    expect($aufbau->standort->fresh()?->is_active)->toBeFalse();
});
