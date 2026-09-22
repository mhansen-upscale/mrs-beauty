<?php

declare(strict_types=1);

use App\Models\AppointmentType;
use App\Models\Practitioner;
use App\Models\Treatment;

/*
|--------------------------------------------------------------------------
| Wer macht welche Behandlung
|--------------------------------------------------------------------------
|
| Die Freigabe haengt an der **Behandlung**, nicht an der Terminart: eine
| Praxis pflegt "wer macht Botox" einmal und nicht je Terminart erneut. Die
| Terminart darf die Freigabe uebersteuern -- das ist der Ausnahmefall
| (Erstgespraech nur bei der Aerztin), nicht die Regel.
|
| Leerer Pivot ist zweideutig, deshalb traegt die Behandlung das Merkmal
| `all_practitioners`: leer und false heisst niemand, leer und true heisst
| alle aktiven.
|
*/

it('erbt die Behandler der Behandlung, wenn die Terminart keine eigenen hat', function (): void {
    alsMandant(organisation('Praxis'));

    $anna = Practitioner::factory()->create(['last_name' => 'Ahrens']);
    $bodo = Practitioner::factory()->create(['last_name' => 'Bruns']);

    $behandlung = Treatment::factory()->create(['all_practitioners' => false]);
    $behandlung->practitioners()->attach($anna);

    $art = AppointmentType::factory()->create(['treatment_id' => $behandlung->getKey()]);

    $freigegeben = $art->freigegebeneBehandler()->pluck('uuid')->map(strval(...))->all();

    expect($freigegeben)->toBe([(string) $anna->uuid]);
    expect($freigegeben)->not->toContain((string) $bodo->uuid);
});

it('meint mit "alle Behandler" auch die, die es spaeter gibt', function (): void {
    // Der Grund fuer das Merkmal: eine Praxis, die "alle" angehakt hat, soll
    // nach einer Neueinstellung nicht jede Behandlung nachpflegen muessen.
    alsMandant(organisation('Praxis'));

    $behandlung = Treatment::factory()->create(['all_practitioners' => true]);
    $art = AppointmentType::factory()->create(['treatment_id' => $behandlung->getKey()]);

    Practitioner::factory()->create(['last_name' => 'Ahrens']);

    expect($art->freigegebeneBehandler())->toHaveCount(1);

    $spaeter = Practitioner::factory()->create(['last_name' => 'Zeller']);

    expect($art->freigegebeneBehandler()->pluck('uuid')->map(strval(...))->all())
        ->toContain((string) $spaeter->uuid);
});

it('zaehlt stillgelegte Behandler nicht mit', function (): void {
    alsMandant(organisation('Praxis'));

    $ruhend = Practitioner::factory()->create(['is_active' => false]);
    $behandlung = Treatment::factory()->create(['all_practitioners' => true]);
    $behandlung->practitioners()->attach($ruhend);

    $art = AppointmentType::factory()->create(['treatment_id' => $behandlung->getKey()]);

    expect($art->freigegebeneBehandler())->toBeEmpty();
});

it('sticht mit einer eigenen Zuordnung an der Terminart die der Behandlung', function (): void {
    alsMandant(organisation('Praxis'));

    $anna = Practitioner::factory()->create(['last_name' => 'Ahrens']);
    $bodo = Practitioner::factory()->create(['last_name' => 'Bruns']);

    $behandlung = Treatment::factory()->create(['all_practitioners' => true]);
    $art = AppointmentType::factory()->create(['treatment_id' => $behandlung->getKey()]);
    $art->practitioners()->attach($bodo);

    $freigegeben = $art->freigegebeneBehandler();

    expect($freigegeben->pluck('uuid')->map(strval(...))->all())->toBe([(string) $bodo->uuid]);
    expect($anna->is_active)->toBeTrue();
});

it('gibt ohne Behandlung und ohne eigene Zuordnung niemanden frei', function (): void {
    // Die Gegenprobe zu "alle aktiven": haette die Aufloesung hier alle
    // geliefert, wuerde jede ungepflegte Terminart jeden Behandler anbieten.
    alsMandant(organisation('Praxis'));

    Practitioner::factory()->create();

    $art = AppointmentType::factory()->create(['treatment_id' => null]);

    expect($art->freigegebeneBehandler())->toBeEmpty();
});
