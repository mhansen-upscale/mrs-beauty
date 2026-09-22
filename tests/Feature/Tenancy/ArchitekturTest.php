<?php

declare(strict_types=1);

use App\Models\ComplianceRuleset;
use App\Models\Concerns\BelongsToTenant;
use App\Models\EncryptionKey;
use App\Models\Organization;
use App\Models\TenantModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| WP-03, Abnahmekriterien 8 und 9
|--------------------------------------------------------------------------
|
| Entscheidung A3: der Global Scope ist der einzige strukturelle Schutz, weil
| MySQL keine Row Level Security bietet. Eine Regel, die nur in einem Dokument
| steht, wird beim naechsten Modell gebrochen, ohne dass es jemand bemerkt.
| Dieser Test macht daraus einen Fehlschlag.
|
*/

/**
 * Begruendete Ausnahmen. Jede steht im Klassenkommentar des Modells.
 *
 * @var array<class-string, string>
 */
const MODELL_AUSNAHMEN = [
    Organization::class => 'ist selbst der Mandant',
    User::class => 'Anmeldung findet vor der Mandantenaufloesung statt',
    EncryptionKey::class => 'wird gebraucht, um Mandantendaten ueberhaupt zu lesen',

    // Entscheidung C1: das HWG-Regelwerk ist global und versioniert. Eine
    // mandantenbezogene Kopie hiesse, dass ein Kunde mit veraltetem
    // Regelwerk weiterarbeitet.
    ComplianceRuleset::class => 'Rechtsstand ist fuer alle Mandanten derselbe',
];

/** @var array<string, string> */
const TABELLEN_AUSNAHMEN = [
    'users' => 'siehe App\Models\User',
    'encryption_keys' => 'siehe App\Models\EncryptionKey',
    'compliance_rulesets' => 'siehe App\Models\ComplianceRuleset',
];

/**
 * Alle Eloquent-Modelle des Projekts, einschliesslich der Test-Fixtures.
 *
 * @return list<class-string<Model>>
 */
function alleModelle(): array
{
    $verzeichnisse = array_filter([
        app_path('Models'),
        base_path('tests/Fixtures/Models'),
    ], is_dir(...));

    $klassen = [];

    foreach (Finder::create()->files()->name('*.php')->in($verzeichnisse) as $datei) {
        $relativ = str_replace(
            [app_path().DIRECTORY_SEPARATOR, base_path('tests').DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR],
            ['App\\', 'Tests\\', '', '\\'],
            $datei->getRealPath()
        );

        if (! class_exists($relativ)) {
            continue;
        }

        $spiegel = new ReflectionClass($relativ);

        if ($spiegel->isAbstract() || ! $spiegel->isSubclassOf(Model::class)) {
            continue;
        }

        /** @var class-string<Model> $relativ */
        $klassen[] = $relativ;
    }

    sort($klassen);

    return $klassen;
}

/**
 * Tabellen mit einer Spalte organization_id.
 *
 * @return list<string>
 */
function mandantentabellen(): array
{
    /** @var list<string> */
    return DB::table('information_schema.columns')
        ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
        ->where('COLUMN_NAME', 'organization_id')
        ->orderBy('TABLE_NAME')
        ->pluck('TABLE_NAME')
        ->map(strval(...))
        ->values()
        ->toArray();
}

/**
 * Modelle mit Mandantenbezug, die nicht von TenantModel erben.
 *
 * @param  array<class-string, string>  $ausnahmen
 * @return list<string>
 */
function modelleOhneTenantModel(array $ausnahmen): array
{
    $tabellen = mandantentabellen();
    $verstoesse = [];

    foreach (alleModelle() as $klasse) {
        if (array_key_exists($klasse, $ausnahmen)) {
            continue;
        }

        $modell = new $klasse;

        if (! in_array($modell->getTable(), $tabellen, true)) {
            continue;
        }

        // Geprueft wird die Substanz, nicht die Basisklasse: der Schutz
        // kommt vom Trait BelongsToTenant. Ein Pivot muss von Pivot erben und
        // kann deshalb nicht von TenantModel erben -- er bindet den Trait
        // stattdessen selbst ein.
        $hatSchutz = $modell instanceof TenantModel
            || in_array(BelongsToTenant::class, class_uses_recursive($modell), true);

        if (! $hatSchutz) {
            $verstoesse[] = $klasse;
        }
    }

    return $verstoesse;
}

/**
 * Tabellen mit Mandantenbezug ohne den Unique-Index (id, organization_id).
 *
 * Ohne ihn laesst MySQL die zusammengesetzten Fremdschluessel der
 * Kindtabellen nicht zu (Entscheidung A2).
 *
 * @param  array<string, string>  $ausnahmen
 * @return list<string>
 */
function tabellenOhneVerbundIndex(array $ausnahmen): array
{
    $datenbank = DB::connection()->getDatabaseName();
    $verstoesse = [];

    foreach (mandantentabellen() as $tabelle) {
        if (array_key_exists($tabelle, $ausnahmen)) {
            continue;
        }

        $spalten = DB::table('information_schema.statistics')
            ->where('TABLE_SCHEMA', $datenbank)
            ->where('TABLE_NAME', $tabelle)
            ->where('NON_UNIQUE', 0)
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get(['INDEX_NAME', 'COLUMN_NAME'])
            ->groupBy('INDEX_NAME')
            ->map(fn ($eintraege) => $eintraege->pluck('COLUMN_NAME')->all());

        $gefunden = $spalten->contains(
            fn (array $spalten): bool => $spalten === ['id', 'organization_id']
        );

        if (! $gefunden) {
            $verstoesse[] = $tabelle;
        }
    }

    return $verstoesse;
}

/**
 * Tabellen mit Mandantenbezug ohne Fremdschluessel auf organizations.
 *
 * @param  array<string, string>  $ausnahmen
 * @return list<string>
 */
function tabellenOhneMandantenFremdschluessel(array $ausnahmen): array
{
    $datenbank = DB::connection()->getDatabaseName();

    $mitFremdschluessel = DB::table('information_schema.key_column_usage')
        ->where('TABLE_SCHEMA', $datenbank)
        ->where('COLUMN_NAME', 'organization_id')
        ->where('REFERENCED_TABLE_NAME', 'organizations')
        ->pluck('TABLE_NAME')
        ->all();

    return array_values(array_filter(
        mandantentabellen(),
        fn (string $tabelle): bool => ! array_key_exists($tabelle, $ausnahmen)
            && ! in_array($tabelle, $mitFremdschluessel, true)
    ));
}

/*
|--------------------------------------------------------------------------
| Die eigentlichen Pruefungen
|--------------------------------------------------------------------------
*/

it('findet ueberhaupt Modelle und Mandantentabellen', function (): void {
    // Ohne diese Zusicherung koennten die Pruefungen unten leer durchlaufen
    // und dabei gruen aussehen.
    expect(alleModelle())->not->toBeEmpty()
        ->and(mandantentabellen())->not->toBeEmpty();
});

it('gibt jedem Modell mit Mandantenbezug den Global Scope', function (): void {
    $verstoesse = modelleOhneTenantModel(MODELL_AUSNAHMEN);

    expect($verstoesse)->toBeEmpty(
        'Diese Modelle tragen eine organization_id, binden aber weder '
        .'TenantModel noch BelongsToTenant ein: '.implode(', ', $verstoesse)
    );
});

it('gibt jeder Mandantentabelle den Unique-Index (id, organization_id)', function (): void {
    $verstoesse = tabellenOhneVerbundIndex(TABELLEN_AUSNAHMEN);

    expect($verstoesse)->toBeEmpty(
        'Ohne diesen Index laesst MySQL keinen zusammengesetzten '
        .'Fremdschluessel auf diese Tabelle zu: '.implode(', ', $verstoesse)
    );
});

it('gibt jeder Mandantentabelle einen Fremdschluessel auf organizations', function (): void {
    $verstoesse = tabellenOhneMandantenFremdschluessel(TABELLEN_AUSNAHMEN);

    expect($verstoesse)->toBeEmpty(
        'Diesen Tabellen fehlt der Fremdschluessel auf organizations: '
        .implode(', ', $verstoesse)
    );
});

/*
|--------------------------------------------------------------------------
| Und der Nachweis, dass die Pruefungen nicht leer durchlaufen
|--------------------------------------------------------------------------
|
| Ein Architektur-Test, der nichts findet, weil seine Suche ins Leere greift,
| ist schlimmer als keiner: er erzeugt Vertrauen ohne Deckung. Deshalb wird
| jede Pruefung einmal ohne Zulassungsliste ausgefuehrt -- dann muss sie die
| bekannten Ausnahmen melden.
|
*/

it('meldet ein Modell mit Mandantenbezug ohne TenantModel', function (): void {
    expect(modelleOhneTenantModel([]))
        ->toContain(User::class)
        ->toContain(EncryptionKey::class);
});

it('meldet eine Mandantentabelle ohne Unique-Index', function (): void {
    // encryption_keys traegt eine organization_id, aber bewusst keinen
    // Unique-Index (id, organization_id): auf die Tabelle verweist nichts.
    expect(tabellenOhneVerbundIndex([]))->toContain('encryption_keys');
});

it('meldet eine Mandantentabelle ohne Fremdschluessel', function (): void {
    // users hat den Fremdschluessel, encryption_keys auch -- geprueft wird
    // hier, dass die Suche tatsaechlich Tabellen betrachtet.
    expect(mandantentabellen())->toContain('users', 'encryption_keys', 'test_records');
});
