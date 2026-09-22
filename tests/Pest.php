<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Testfall-Basis
|--------------------------------------------------------------------------
|
| Feature-Tests laufen gegen eine echte MySQL-Datenbank (siehe phpunit.xml),
| nicht gegen SQLite im Speicher. Sperrverhalten, generierte Spalten und
| JSON-Abfragen bildet SQLite anders ab -- ein gruener Test waere dort
| genau bei den Stellen wertlos, an denen es darauf ankommt.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Mandanten
|--------------------------------------------------------------------------
*/

/** Legt eine Organisation samt Schluesselsatz an. */
function organisation(string $name = 'Praxis'): Organization
{
    return Organization::factory()->create(['name' => $name]);
}

/** Legt eine Organisation an und setzt sie als geltenden Mandanten. */
function alsMandant(?Organization $organization = null): Organization
{
    $organization ??= organisation();

    app(TenantContext::class)->set($organization);

    return $organization;
}

/** Vergisst den Mandanten. */
function ohneMandant(): void
{
    app(TenantContext::class)->forget();
}
