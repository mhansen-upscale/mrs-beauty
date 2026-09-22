<?php

declare(strict_types=1);

use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\TestChild;
use Tests\Fixtures\Models\TestRecord;

/*
|--------------------------------------------------------------------------
| WP-03, Abnahmekriterien 10 bis 13 -- UUID v7 als BINARY(16)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

it('legt die ID als BINARY(16) in der Datenbank ab', function (): void {
    $spalte = (object) (array) DB::selectOne(
        'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH AS laenge
         FROM information_schema.columns
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [DB::connection()->getDatabaseName(), 'test_records', 'id']
    );

    expect($spalte->DATA_TYPE)->toBe('binary')
        ->and((int) ($spalte->laenge ?? 0))->toBe(16);
});

it('liefert die kanonische Form ueber das Attribut uuid', function (): void {
    $datensatz = TestRecord::create(['label' => 'egal']);

    expect($datensatz->uuid)->toBeString()
        ->and(Uuid::isCanonical((string) $datensatz->uuid))->toBeTrue()
        ->and(strlen($datensatz->getKey()))->toBe(16)
        ->and(Uuid::toBinary((string) $datensatz->uuid))->toBe($datensatz->getKey());
});

it('erzeugt UUIDs der Version 7', function (): void {
    $uuid = (string) TestRecord::create(['label' => 'egal'])->uuid;

    // Die Version steht im ersten Zeichen der dritten Gruppe.
    expect(explode('-', $uuid)[2][0])->toBe('7');
});

it('erzeugt aufsteigend sortierbare IDs', function (): void {
    $ids = collect(range(1, 5))
        ->map(fn (int $i): string => TestRecord::create(['label' => "nr {$i}"])->getKey())
        ->all();

    $sortiert = $ids;
    sort($sortiert);

    expect($ids)->toBe($sortiert);
});

it('laesst Beziehungen und Eager Loading ohne Sonderbehandlung arbeiten', function (): void {
    $datensatz = TestRecord::create(['label' => 'Elternteil']);
    $datensatz->children()->createMany([
        ['title' => 'eins'],
        ['title' => 'zwei'],
    ]);

    $geladen = TestRecord::with('children')->whereUuid((string) $datensatz->uuid)->firstOrFail();

    expect($geladen->children)->toHaveCount(2)
        ->and($geladen->children->pluck('title')->all())->toBe(['eins', 'zwei'])
        ->and(TestChild::query()->first()?->testRecord?->getKey())->toBe($datensatz->getKey());
});

it('findet ueber whereUuid in beiden Formen', function (): void {
    $datensatz = TestRecord::create(['label' => 'egal']);

    expect(TestRecord::whereUuid((string) $datensatz->uuid)->exists())->toBeTrue()
        ->and(TestRecord::whereUuid($datensatz->getKey())->exists())->toBeTrue();
});

it('verbirgt die Rohbytes in der Serialisierung', function (): void {
    $serialisiert = TestRecord::create(['label' => 'egal'])->toArray();

    expect($serialisiert)->not->toHaveKey('id')
        ->and($serialisiert)->toHaveKey('uuid');
});
