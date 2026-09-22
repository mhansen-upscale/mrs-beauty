<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Die Bauteile haben die API, die sie haben
|--------------------------------------------------------------------------
|
| Das Projekt steht auf radix-vue v1. Dessen CheckboxRoot heisst `checked`
| und `update:checked`, nicht `modelValue`. Ein `:model-value` daran faellt
| als gewoehnliches Attribut durch: die Schaltflaeche sieht richtig aus, sie
| laesst sich anklicken, sie zeigt das Haekchen -- und der gebundene Wert
| aendert sich nie.
|
| Genau das ist in WP-12 passiert: die Einwilligung war sichtbar gesetzt und
| im Formular falsch. Betroffen waren ausserdem die Freigaben der Terminarten,
| die Standortzuordnung der Behandler und das Uebersteuern im Buchungsdialog.
| Keiner dieser Fehler erzeugt eine Meldung.
|
*/

/** @return list<string> */
function vueDateien(): array
{
    $dateien = [];

    foreach (Finder::create()->files()->name('*.vue')->in(resource_path('js')) as $datei) {
        $dateien[] = $datei->getRealPath();
    }

    sort($dateien);

    return $dateien;
}

/**
 * @param  list<string>  $ausnahmen  Dateinamen, die ausgenommen bleiben.
 * @return list<string>
 */
function checkboxenMitFalscherApi(array $ausnahmen = ['Checkbox.vue']): array
{
    $verstoesse = [];

    foreach (vueDateien() as $pfad) {
        if (in_array(basename($pfad), $ausnahmen, true)) {
            continue;
        }

        $inhalt = (string) file_get_contents($pfad);

        // Ein <Checkbox ...> bis zum schliessenden Winkel, ueber Zeilen hinweg.
        if (preg_match_all('/<Checkbox\b[^>]*>/s', $inhalt, $treffer) === 0) {
            continue;
        }

        foreach ($treffer[0] as $marke) {
            if (str_contains($marke, 'model-value') || str_contains($marke, 'modelValue')) {
                $verstoesse[] = str_replace(base_path().'/', '', $pfad);
            }
        }
    }

    return array_values(array_unique($verstoesse));
}

it('bindet jede Checkbox ueber checked', function (): void {
    $verstoesse = checkboxenMitFalscherApi();

    expect($verstoesse)->toBeEmpty(
        "Diese Dateien binden eine Checkbox ueber model-value statt checked:\n".implode("\n", $verstoesse)
    );
});

it('findet ueberhaupt Checkboxen', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    $mitCheckbox = array_filter(
        vueDateien(),
        fn (string $pfad): bool => basename($pfad) !== 'Checkbox.vue'
            && str_contains((string) file_get_contents($pfad), '<Checkbox')
    );

    expect(count($mitCheckbox))->toBeGreaterThanOrEqual(4);
});

it('erkennt die falsche API, wenn es sie gibt', function (): void {
    // Die Gegenprobe: das Muster selbst muss greifen.
    expect(preg_match('/<Checkbox\b[^>]*model-value[^>]*>/s', '<Checkbox :model-value="x" />'))->toBe(1);
});
