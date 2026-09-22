<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Entscheidung A7
|--------------------------------------------------------------------------
|
| "Alle Zeiten UTC als DATETIME, niemals TIMESTAMP" -- wegen der 2038-Grenze
| und der impliziten Zeitzonenkonvertierung, die MySQL auf TIMESTAMP-Spalten
| anwendet. Eine Entscheidung, die nur in einem Dokument steht, wird beim
| naechsten `$table->timestamps()` gebrochen, ohne dass es jemand bemerkt.
| Dieser Test macht daraus einen Fehlschlag.
|
| Tabellen fremder Pakete sind ausgenommen: wir kontrollieren ihre
| Migrationen nicht.
|
*/

const FREMDE_TABELLEN = [
    'migrations',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'sessions',
];

it('verwendet nirgends TIMESTAMP statt DATETIME', function (): void {
    $datenbank = DB::connection()->getDatabaseName();

    $treffer = DB::table('information_schema.columns')
        ->select(['TABLE_NAME', 'COLUMN_NAME'])
        ->where('TABLE_SCHEMA', $datenbank)
        ->where('DATA_TYPE', 'timestamp')
        ->whereNotIn('TABLE_NAME', FREMDE_TABELLEN)
        ->where('TABLE_NAME', 'not like', 'pulse\_%')
        ->orderBy('TABLE_NAME')
        ->orderBy('COLUMN_NAME')
        ->get()
        ->map(fn (object $spalte): string => "{$spalte->TABLE_NAME}.{$spalte->COLUMN_NAME}")
        ->all();

    expect($treffer)->toBeEmpty(
        'Entscheidung A7 verletzt, TIMESTAMP statt DATETIME: '.implode(', ', $treffer)
    );
});
