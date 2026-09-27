<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Support\Uuid;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Ausdruecklich und ohne RefreshDatabase: die Migration aendert das Schema,
// und MySQL schliesst dabei jede offene Transaktion.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| WP-34a, Abnahmekriterium 10 -- die Migration uebernimmt den Bestand
|--------------------------------------------------------------------------
|
| Bis hier kannte das Produkt ein Kennzeichen, `users.is_super_admin`. Wer es
| trug, darf nach der Migration weiterhin alles -- als Super-Admin. Ein Test,
| der nur das neue Schema sieht, prueft genau das nicht.
|
*/

const BETREIBERROLLEN_MIGRATION = 'database/migrations/2026_09_27_120000_betreiberrollen.php';

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    // Die naechste Datei soll ein vollstaendiges Schema vorfinden.
    Artisan::call('migrate', ['--force' => true]);
});

it('macht aus jedem bisherigen Super-Admin einen Super-Admin', function (): void {
    Artisan::call('migrate:rollback', ['--path' => BETREIBERROLLEN_MIGRATION, '--force' => true]);

    expect(Schema::hasColumn('users', 'is_super_admin'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'operator_role'))->toBeFalse();

    $neuesKonto = fn (string $email, bool $betreiber): string => tap(Uuid::generate(), fn (string $id) => DB::table('users')->insert([
        'id' => $id,
        'name' => $email,
        'email' => $email,
        'password' => 'x',
        'is_super_admin' => $betreiber,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $support = $neuesKonto('support@mrs-beauty.test', true);
    $praxis = $neuesKonto('empfang@praxis.test', false);

    Artisan::call('migrate', ['--path' => BETREIBERROLLEN_MIGRATION, '--force' => true]);

    expect(Schema::hasColumn('users', 'is_super_admin'))->toBeFalse()
        ->and(DB::table('users')->where('id', $support)->value('operator_role'))->toBe(OperatorRole::SuperAdmin->value)
        ->and(DB::table('users')->where('id', $praxis)->value('operator_role'))->toBeNull();
});
