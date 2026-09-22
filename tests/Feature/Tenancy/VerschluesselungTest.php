<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Tenancy\Exceptions\KeyRevoked;
use App\Tenancy\KeyRing;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\TestRecord;

/*
|--------------------------------------------------------------------------
| WP-03, Abnahmekriterien 14 bis 19 -- Envelope Encryption und blinde Indizes
|--------------------------------------------------------------------------
*/

/** Liest die Rohbytes an Eloquent vorbei. */
function rohwert(string $spalte, string $id): ?string
{
    $zeile = DB::selectOne(
        "SELECT `{$spalte}` AS wert FROM test_records WHERE id = ?",
        [$id]
    );

    return $zeile?->wert;
}

it('legt ein verschluesseltes Feld nicht im Klartext ab', function (): void {
    alsMandant();

    $datensatz = TestRecord::create(['label' => 'Geheime Behandlung']);

    $roh = rohwert('label', $datensatz->getKey());

    expect($roh)->not->toBeNull()
        ->and($roh)->not->toContain('Geheime Behandlung')
        ->and($datensatz->fresh()?->label)->toBe('Geheime Behandlung');
});

it('erzeugt in zwei Organisationen verschiedene Chiffrate', function (): void {
    alsMandant();
    $a = TestRecord::create(['label' => 'derselbe Text']);

    alsMandant(organisation('Andere Praxis'));
    $b = TestRecord::create(['label' => 'derselbe Text']);

    expect(rohwert('label', $a->getKey()))
        ->not->toBe(rohwert('label', $b->getKey()));
});

it('erzeugt bei jedem Schreibvorgang ein anderes Chiffrat', function (): void {
    alsMandant();

    $eins = TestRecord::create(['label' => 'derselbe Text']);
    $zwei = TestRecord::create(['label' => 'derselbe Text']);

    // Eigener Nonce je Schreibvorgang. Genau deswegen ist ein
    // verschluesseltes Feld nicht durchsuchbar.
    expect(rohwert('label', $eins->getKey()))
        ->not->toBe(rohwert('label', $zwei->getKey()));
});

it('findet ueber den blinden Index den exakten Wert', function (): void {
    alsMandant();

    TestRecord::create(['label' => 'egal', 'email' => 'Max@Praxis.de']);

    // Normalisiert: Gross-, Kleinschreibung und Leerraum spielen keine Rolle.
    expect(TestRecord::whereBlind('email', 'max@praxis.de ')->count())->toBe(1)
        ->and(TestRecord::whereBlind('email', 'andere@praxis.de')->count())->toBe(0);
});

it('erzeugt in zwei Organisationen verschiedene blinde Indizes', function (): void {
    alsMandant();
    $a = TestRecord::create(['label' => 'egal', 'email' => 'max@praxis.de']);

    alsMandant(organisation('Andere Praxis'));
    $b = TestRecord::create(['label' => 'egal', 'email' => 'max@praxis.de']);

    // Sonst liesse sich ueber einen Datenbankauszug feststellen, wer bei
    // mehreren Praxen Kunde ist.
    expect(rohwert('email_bidx', $a->getKey()))
        ->not->toBe(rohwert('email_bidx', $b->getKey()));
});

it('liest einen Datensatz auch quer zu den Mandanten mit dem richtigen Schluessel', function (): void {
    alsMandant();
    $a = TestRecord::create(['label' => 'gehoert A']);

    alsMandant(organisation('Andere Praxis'));

    // Der Schluessel richtet sich nach der organization_id des Datensatzes,
    // nicht nach dem gerade gesetzten Mandanten.
    $gelesen = app(TenantContext::class)->acrossTenants(
        'Test des Schluesselbezugs',
        fn (): ?TestRecord => TestRecord::query()->whereKey($a->getKey())->first()
    );

    expect($gelesen?->label)->toBe('gehoert A');
});

it('macht die Daten nach Widerruf des Schluessels unlesbar', function (): void {
    $organisation = alsMandant();
    $datensatz = TestRecord::create(['label' => 'Geheime Behandlung']);

    app(KeyRing::class)->revoke($organisation, 'Kuendigung');

    // Der Rohwert steht noch da -- nur lesen kann ihn niemand mehr.
    expect(rohwert('label', $datensatz->getKey()))->not->toBeNull();

    TestRecord::query()->whereKey($datensatz->getKey())->first()?->label;
})->throws(KeyRevoked::class);

it('meldet einen fehlenden Schluessel verstaendlich', function (): void {
    $organisation = Organization::factory()->ohneSchluessel()->create();
    alsMandant($organisation);

    TestRecord::create(['label' => 'egal']);
})->throws(KeyRevoked::class);

it('haelt den entpackten Schluessel aus Backtraces heraus', function (): void {
    $organisation = alsMandant();

    $schluessel = app(KeyRing::class)->for($organisation->getKey());

    expect(print_r($schluessel, true))
        ->not->toContain($schluessel->dataEncryptionKey)
        ->and(print_r($schluessel, true))->toContain('***');
});
