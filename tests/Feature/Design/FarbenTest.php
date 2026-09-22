<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| docs/design/farben.md, Abschnitt "Umsetzung"
|--------------------------------------------------------------------------
|
| "Keine festen Farbwerte im Code, weder als Hex noch als Tailwind-Palette
|  (bg-blue-500)."
|
| Der Grund ist nicht Geschmack: die Buchungsseite ueberschreibt spaeter
| --primary und --ring mit der Markenfarbe der Praxis. Jede Klasse, die an den
| Tokens vorbei faerbt, bleibt dabei stehen -- und zwar genau an der Stelle,
| an der es auffaellt.
|
| Geprueft wird die **Darstellung**: Klassennamen. Ein Farbwert, den eine
| Praxis selbst waehlt und der in der Datenbank steht (appointment_types.color),
| ist keine Gestaltung, sondern ein Datum.
|
*/

/** @return list<string> */
function oberflaechendateien(): array
{
    $dateien = [];

    foreach (Finder::create()->files()->name(['*.vue', '*.ts', '*.blade.php'])->in(resource_path()) as $datei) {
        $dateien[] = $datei->getRealPath();
    }

    sort($dateien);

    return $dateien;
}

/**
 * @param  list<string>  $ausnahmen  Dateinamen, die ausgenommen bleiben.
 * @return list<string>
 */
function festeFarben(array $ausnahmen = []): array
{
    $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan'
        .'|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    $praefix = 'bg|text|border|ring|fill|stroke|from|to|via|divide|outline|decoration|shadow|accent|caret|placeholder';

    $muster = [
        // bg-blue-500 und Verwandte
        "/\b(?:{$praefix})-(?:{$palette})-[0-9]{2,3}\b/",
        // bg-[#1b1b18], text-[rgb(...)]
        "/\b(?:{$praefix})-\[(?:#|rgb|hsl)/i",
    ];

    $verstoesse = [];

    foreach (oberflaechendateien() as $pfad) {
        $name = basename($pfad);

        if (in_array($name, $ausnahmen, true)) {
            continue;
        }

        $inhalt = (string) file_get_contents($pfad);

        foreach ($muster as $regex) {
            if (preg_match_all($regex, $inhalt, $treffer) > 0) {
                foreach (array_unique($treffer[0]) as $fund) {
                    $verstoesse[] = str_replace(base_path().'/', '', $pfad).': '.$fund;
                }
            }
        }
    }

    sort($verstoesse);

    return $verstoesse;
}

it('faerbt ausschliesslich ueber die Tokens', function (): void {
    $verstoesse = festeFarben();

    expect($verstoesse)->toBeEmpty(
        "Diese Stellen faerben an den Tokens vorbei:\n".implode("\n", $verstoesse)
    );
});

it('findet ueberhaupt Oberflaechendateien', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    expect(count(oberflaechendateien()))->toBeGreaterThan(50);
});

it('erkennt eine feste Farbe, wenn es eine gibt', function (): void {
    // Die Gegenprobe: das Muster selbst muss greifen.
    $treffer = preg_match('/\b(?:bg|text)-(?:blue|amber)-[0-9]{2,3}\b/', 'class="bg-blue-500 text-amber-600"');

    expect($treffer)->toBe(1);
});
