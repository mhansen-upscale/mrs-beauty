<?php

declare(strict_types=1);

use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Tests\Fixtures\Models\TestChild;
use Tests\Fixtures\Models\TestRecord;

/*
|--------------------------------------------------------------------------
| WP-03, Abnahmekriterien 1 bis 7
|--------------------------------------------------------------------------
*/

it('wirft bei einer Abfrage ohne Mandantenkontext', function (): void {
    ohneMandant();

    TestRecord::query()->get();
})->throws(TenantContextMissing::class);

it('blendet Datensaetze fremder Mandanten aus', function (): void {
    $a = alsMandant();
    TestRecord::create(['label' => 'gehoert A']);

    $b = alsMandant(organisation('Andere Praxis'));
    TestRecord::create(['label' => 'gehoert B']);

    expect(TestRecord::query()->count())->toBe(1)
        ->and(TestRecord::query()->first()?->label)->toBe('gehoert B');

    app(TenantContext::class)->set($a);

    expect(TestRecord::query()->count())->toBe(1)
        ->and(TestRecord::query()->first()?->label)->toBe('gehoert A');
});

it('liefert null fuer die ID eines fremden Mandanten', function (): void {
    alsMandant();
    $fremd = TestRecord::create(['label' => 'fremd']);

    alsMandant(organisation('Andere Praxis'));

    expect(TestRecord::find($fremd->getKey()))->toBeNull();
});

it('setzt organization_id beim Anlegen automatisch', function (): void {
    $organisation = alsMandant();

    $datensatz = TestRecord::create(['label' => 'egal']);

    expect($datensatz->organization_id)->toBe($organisation->getKey());
});

it('laesst organization_id nicht per Massenzuweisung setzen', function (): void {
    alsMandant();
    $fremd = organisation('Andere Praxis');

    // Zwei Ebenen greifen hier. isFillable() weist das Feld ab, und
    // Model::shouldBeStrict() macht aus dem Abweisen eine Ausnahme statt
    // eines stillen Verwerfens. In Produktion ist die Strenge aus -- dort
    // wird der Wert verworfen, nicht uebernommen. Beides ist sicher, nur
    // faellt es beim Entwickeln sofort auf.
    TestRecord::create([
        'label' => 'egal',
        'organization_id' => $fremd->getKey(),
    ]);
})->throws(MassAssignmentException::class);

it('verwirft organization_id auch ohne strenge Modelle', function (): void {
    $organisation = alsMandant();
    $fremd = organisation('Andere Praxis');

    Model::preventSilentlyDiscardingAttributes(false);

    $datensatz = TestRecord::create([
        'label' => 'egal',
        'organization_id' => $fremd->getKey(),
    ]);

    expect($datensatz->organization_id)->toBe($organisation->getKey());
});

it('laesst organization_id nicht nachtraeglich aendern', function (): void {
    alsMandant();
    $datensatz = TestRecord::create(['label' => 'egal']);
    $fremd = organisation('Andere Praxis');

    $datensatz->organization_id = $fremd->getKey();
    $datensatz->save();
})->throws(TenantMismatch::class);

it('verhindert einen mandantenuebergreifenden Verweis auf Datenbankebene', function (): void {
    alsMandant();
    $fremderDatensatz = TestRecord::create(['label' => 'gehoert A']);

    alsMandant(organisation('Andere Praxis'));

    // Der zusammengesetzte Fremdschluessel (test_record_id, organization_id)
    // findet in test_records keine passende Zeile: die ID gehoert zu A, die
    // organization_id zu B.
    TestChild::create([
        'title' => 'Kind',
        'test_record_id' => $fremderDatensatz->getKey(),
    ]);
})->throws(QueryException::class);

it('setzt den Scope nur innerhalb von acrossTenants aus', function (): void {
    alsMandant();
    TestRecord::create(['label' => 'A']);

    alsMandant(organisation('Andere Praxis'));
    TestRecord::create(['label' => 'B']);

    $alle = app(TenantContext::class)->acrossTenants(
        'Test der Mandantengrenze',
        fn (): int => TestRecord::query()->count()
    );

    expect($alle)->toBe(2)
        ->and(app(TenantContext::class)->scopeIsSuspended())->toBeFalse()
        ->and(TestRecord::query()->count())->toBe(1);
});

it('stellt den Scope auch nach einer Ausnahme wieder her', function (): void {
    alsMandant();

    try {
        app(TenantContext::class)->acrossTenants('Test der Wiederherstellung', function (): never {
            throw new RuntimeException('abgebrochen');
        });
    } catch (RuntimeException) {
        // erwartet
    }

    expect(app(TenantContext::class)->scopeIsSuspended())->toBeFalse();
});

it('stellt den vorherigen Mandanten nach runAs wieder her', function (): void {
    $a = alsMandant();
    $b = organisation('Andere Praxis');

    $innen = app(TenantContext::class)->runAs($b, fn (): ?string => app(TenantContext::class)->id());

    expect($innen)->toBe($b->getKey())
        ->and(app(TenantContext::class)->id())->toBe($a->getKey());
});
