<?php

declare(strict_types=1);

use App\Casts\Encrypted;
use App\Contracts\HasPersonalData;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Zwei Regeln, die sich nur als Test durchsetzen lassen
|--------------------------------------------------------------------------
*/

/**
 * Der Global Scope laesst sich mit Eloquents eigener API abstreifen --
 * withoutGlobalScope() und withoutGlobalScopes() umgehen die
 * Mandantentrennung, ohne dass ein Protokolleintrag entsteht.
 *
 * Im Anwendungscode ist das deshalb untersagt. Der benannte Kanal heisst
 * TenantContext::acrossTenants() und protokolliert sich selbst (WP-05).
 * In Tests ist es erlaubt -- dort waere die Protokollierung nur Rauschen.
 */
it('umgeht den Mandanten-Scope nirgends an der Protokollierung vorbei', function (): void {
    $treffer = [];

    foreach (Finder::create()->files()->name('*.php')->in(app_path()) as $datei) {
        $inhalt = (string) file_get_contents($datei->getRealPath());

        if (preg_match('/withoutGlobalScopes?\s*\(/', $inhalt) === 1) {
            $treffer[] = str_replace(base_path().'/', '', $datei->getRealPath());
        }
    }

    expect($treffer)->toBeEmpty(
        'Diese Dateien umgehen den Global Scope an der Protokollierung vorbei. '
        .'Der benannte Kanal ist TenantContext::acrossTenants(): '.implode(', ', $treffer)
    );
});

/**
 * Wer verschluesselte Felder hat, hat personenbezogene -- sonst waere die
 * Verschluesselung sinnlos. Also muss er sie auch fuer die Maskierung
 * benennen (Entscheidung C4).
 */
it('erklaert zu jedem verschluesselten Feld ein personenbezogenes', function (): void {
    $verstoesse = [];

    $verzeichnisse = array_filter([
        app_path('Models'),
        base_path('tests/Fixtures/Models'),
    ], is_dir(...));

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

        /** @var Model $modell */
        $modell = new $klasse;

        $verschluesselt = array_keys(array_filter(
            $modell->getCasts(),
            fn (mixed $cast): bool => $cast === Encrypted::class
        ));

        if ($verschluesselt === []) {
            continue;
        }

        if (! $modell instanceof HasPersonalData) {
            $verstoesse[] = "{$klasse} (kein HasPersonalData)";

            continue;
        }

        foreach (array_diff($verschluesselt, $modell->personalFields()) as $feld) {
            $verstoesse[] = "{$klasse}::\${$feld}";
        }
    }

    expect($verstoesse)->toBeEmpty(
        'Diese verschluesselten Felder sind nicht als personenbezogen erklaert '
        .'und werden bei maskierter Impersonation nicht ersetzt: '.implode(', ', $verstoesse)
    );
});
