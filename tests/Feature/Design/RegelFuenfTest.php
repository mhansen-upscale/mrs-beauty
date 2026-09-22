<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Regel 5, ausfuehrbar: Inhalte sind Daten
|--------------------------------------------------------------------------
|
| Bis WP-20 war eine eingegangene Nachricht eine Zeichenkette in einer Spalte.
| Mit dem Posteingang (WP-21) wird sie **angezeigt** -- und damit stellt sich
| zum ersten Mal die Frage, ob sie dabei etwas tun kann.
|
| `v-html` ist der kuerzeste Weg zu einer Nachricht, die etwas tut: Vue setzt
| den Inhalt dann als Auszeichnung ein, mitsamt allem, was darin steht. Bei
| einem Kanal, ueber den Fremde schreiben, ist das keine theoretische Luecke.
|
*/

/**
 * @param  list<string>  $ausnahmen
 * @return list<string>
 */
function dateienMitVHtml(array $ausnahmen = []): array
{
    $verstoesse = [];

    foreach (Finder::create()->files()->name('*.vue')->in(resource_path('js')) as $datei) {
        if (in_array($datei->getFilename(), $ausnahmen, true)) {
            continue;
        }

        $inhalt = (string) file_get_contents((string) $datei->getRealPath());

        // Auf das Attribut, nicht auf das Wort: ein Kommentar, der die Regel
        // erklaert, ist kein Verstoss gegen sie.
        if (preg_match('/\bv-html\s*=|\.innerHTML\s*=/', $inhalt) === 1) {
            $verstoesse[] = $datei->getFilename();
        }
    }

    sort($verstoesse);

    return $verstoesse;
}

it('setzt nirgends fremden Inhalt als Auszeichnung ein', function (): void {
    expect(dateienMitVHtml())->toBeEmpty(
        'Diese Dateien verwenden v-html oder innerHTML: '.implode(', ', dateienMitVHtml())
    );
});

it('findet ueberhaupt Oberflaechendateien', function (): void {
    // Die Gegenprobe: ein Test, der nichts durchsucht, ist immer gruen.
    $dateien = iterator_to_array(Finder::create()->files()->name('*.vue')->in(resource_path('js')));

    expect(count($dateien))->toBeGreaterThan(30);
});

it('erkennt ein v-html, wenn es eines gibt', function (): void {
    // Die zweite Gegenprobe: der Sucher findet wirklich etwas.
    $pfad = resource_path('js/pages/GegenprobeRegelFuenf.vue');

    file_put_contents($pfad, "<template>\n    <p v-html=\"inhalt\"></p>\n</template>\n");

    try {
        expect(dateienMitVHtml())->toContain('GegenprobeRegelFuenf.vue');
    } finally {
        @unlink($pfad);
    }
});
