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

/*
|--------------------------------------------------------------------------
| Heading ist mehrwurzelig
|--------------------------------------------------------------------------
|
| Das Bauteil liefert Ueberschrift **und** Trennlinie. In einer Flex-Zeile
| wird die Trennlinie damit zum zweiten Flex-Element -- sie ist voll breit und
| schiebt alles Weitere in die naechste Zeile. Genau so landeten die
| "anlegen"-Knoepfe eine Zeile zu tief und linksbuendig, auf jeder Seite mit
| Stammdaten.
|
| Dieselbe Falle wie beim AktionsButton in WP-11: ein mehrwurzeliges Bauteil
| verhaelt sich in einem Layout nicht wie ein einzelnes Element, und Vue sagt
| dazu nichts. Seitenaktionen gehoeren deshalb in die Werkzeugzeile von
| DataTable (`#werkzeuge`).
|
*/

/**
 * Dateien, die <Heading> in einen Flex- oder Grid-Container setzen.
 *
 * @return list<string>
 */
function headingInLayoutzeile(): array
{
    $verstoesse = [];

    foreach (vueDateien() as $pfad) {
        if (basename($pfad) === 'Heading.vue') {
            continue;
        }

        $inhalt = (string) file_get_contents($pfad);

        // Das oeffnende <div ...> unmittelbar vor einem <Heading>, ueber
        // Zeilen hinweg -- dazwischen darf nur Leerraum stehen.
        if (preg_match_all('/<div\b([^>]*)>\s*<Heading\b/s', $inhalt, $treffer) === 0) {
            continue;
        }

        foreach ($treffer[1] as $attribute) {
            if (preg_match('/\bclass="[^"]*\b(flex|grid)\b/', $attribute) === 1) {
                $verstoesse[] = str_replace(base_path().'/', '', $pfad);
            }
        }
    }

    return array_values(array_unique($verstoesse));
}

it('setzt Heading in keine Flex- oder Grid-Zeile', function (): void {
    $verstoesse = headingInLayoutzeile();

    expect($verstoesse)->toBeEmpty(
        "Heading ist mehrwurzelig -- die Trennlinie bricht die Zeile um.\n"
        ."Seitenaktionen gehoeren in <DataTable #werkzeuge>:\n".implode("\n", $verstoesse)
    );
});

it('erkennt Heading in einer Flex-Zeile, wenn es sie gibt', function (): void {
    // Die Gegenprobe: das Muster selbst muss greifen.
    $beispiel = '<div class="flex flex-wrap items-start justify-between gap-3">'."\n".'    <Heading title="X" />';

    expect(preg_match('/<div\b([^>]*)>\s*<Heading\b/s', $beispiel, $treffer))->toBe(1);

    $attribute = $treffer[1] ?? '';

    expect($attribute)->not->toBe('')
        ->and(preg_match('/\bclass="[^"]*\b(flex|grid)\b/', $attribute))->toBe(1);
});

it('findet ueberhaupt Seiten mit Heading', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    $mitHeading = array_filter(
        vueDateien(),
        fn (string $pfad): bool => basename($pfad) !== 'Heading.vue'
            && str_contains((string) file_get_contents($pfad), '<Heading')
    );

    expect(count($mitHeading))->toBeGreaterThanOrEqual(5);
});
