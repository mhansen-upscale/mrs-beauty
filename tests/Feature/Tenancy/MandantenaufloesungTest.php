<?php

declare(strict_types=1);

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| Aufloesung des Mandanten aus dem angemeldeten Benutzer
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    ohneMandant();

    Route::middleware('web')->get('/_test/mandant', function (): array {
        return ['mandant' => app(TenantContext::class)->current()?->slug];
    });
});

it('setzt den Mandanten aus dem angemeldeten Benutzer', function (): void {
    $organisation = organisation('Praxis Nord');
    $benutzer = User::factory()->fuer($organisation)->create();

    actingAs($benutzer)
        ->getJson('/_test/mandant')
        ->assertOk()
        ->assertJson(['mandant' => $organisation->slug]);
});

it('laesst den Kontext leer, wenn niemand angemeldet ist', function (): void {
    getJson('/_test/mandant')
        ->assertOk()
        ->assertJson(['mandant' => null]);
});

it('laesst den Kontext leer bei einem Benutzer ohne Organisation', function (): void {
    // Der Super-Admin aus WP-34 gehoert zu keiner Organisation.
    $benutzer = User::factory()->create();

    actingAs($benutzer)
        ->getJson('/_test/mandant')
        ->assertOk()
        ->assertJson(['mandant' => null]);
});
