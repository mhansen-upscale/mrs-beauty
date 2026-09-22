<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\Fixtures\Models\TestRecord;

/*
|--------------------------------------------------------------------------
| Rohbytes gehoeren nicht in JSON
|--------------------------------------------------------------------------
|
| Entscheidung A4 legt Schluessel als BINARY(16) ab. Steht eine solche Spalte
| in toArray(), bricht json_encode() mit "Malformed UTF-8 characters" -- und
| zwar in jeder Inertia-Antwort, die das Modell teilt. Die Meldung zeigt dann
| auf alles ausser die Ursache.
|
| Zulaessig ist eine binaere Spalte nur, wenn sie entweder verborgen ist oder
| einen Cast hat, der eine lesbare Zeichenkette liefert (z. B. App\Casts\Encrypted).
|
*/

/**
 * @return list<class-string<Model>>
 */
function alleModelleFuerSerialisierung(): array
{
    $verzeichnisse = array_filter([
        app_path('Models'),
        base_path('tests/Fixtures/Models'),
    ], is_dir(...));

    $klassen = [];

    foreach (Finder::create()->files()->name('*.php')->in($verzeichnisse) as $datei) {
        $klasse = str_replace(
            [app_path().DIRECTORY_SEPARATOR, base_path('tests').DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR],
            ['App\\', 'Tests\\', '', '\\'],
            $datei->getRealPath()
        );

        if (! class_exists($klasse)) {
            continue;
        }

        $spiegel = new ReflectionClass($klasse);

        if ($spiegel->isAbstract() || ! $spiegel->isSubclassOf(Model::class)) {
            continue;
        }

        /** @var class-string<Model> $klasse */
        $klassen[] = $klasse;
    }

    sort($klassen);

    return $klassen;
}

/**
 * Binaere Spalten, die weder verborgen noch gecastet sind.
 *
 * @return list<string>
 */
function ungeschuetzteRohbytes(): array
{
    $datenbank = DB::connection()->getDatabaseName();
    $verstoesse = [];

    foreach (alleModelleFuerSerialisierung() as $klasse) {
        $modell = new $klasse;

        $binaerspalten = DB::table('information_schema.columns')
            ->where('TABLE_SCHEMA', $datenbank)
            ->where('TABLE_NAME', $modell->getTable())
            ->whereIn('DATA_TYPE', ['binary', 'varbinary', 'blob', 'mediumblob', 'longblob'])
            ->pluck('COLUMN_NAME');

        $verborgen = $modell->getHidden();
        $gecastet = array_keys($modell->getCasts());

        foreach ($binaerspalten as $spalte) {
            $spalte = (string) $spalte;

            if (in_array($spalte, $verborgen, true) || in_array($spalte, $gecastet, true)) {
                continue;
            }

            $verstoesse[] = "{$klasse}::\${$spalte}";
        }
    }

    return $verstoesse;
}

it('serialisiert keine Rohbytes', function (): void {
    $verstoesse = ungeschuetzteRohbytes();

    expect($verstoesse)->toBeEmpty(
        'Diese binaeren Spalten sind weder verborgen noch gecastet und brechen '
        .'json_encode(): '.implode(', ', $verstoesse)
    );
});

it('findet ueberhaupt binaere Spalten', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    $binaer = DB::table('information_schema.columns')
        ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
        ->whereIn('DATA_TYPE', ['binary', 'varbinary', 'blob'])
        ->count();

    expect($binaer)->toBeGreaterThan(0);
});

it('haelt jedes Modell JSON-fest', function (): void {
    // Die Gegenprobe zur Schemapruefung: ein tatsaechlich gefuelltes Modell
    // muss sich kodieren lassen.
    $organisation = alsMandant();
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();
    $einladung = Invitation::factory()->create();
    $datensatz = TestRecord::create(['label' => 'egal', 'email' => 'a@b.de']);

    foreach ([$organisation, $benutzer, $einladung, $datensatz] as $modell) {
        expect(json_encode($modell->toArray()))
            ->not->toBeFalse($modell::class.' laesst sich nicht kodieren: '.json_last_error_msg());
    }
});
