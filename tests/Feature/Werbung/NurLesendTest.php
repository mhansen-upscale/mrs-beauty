<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Geschrieben wird an genau einer Stelle
|--------------------------------------------------------------------------
|
| **WP-26** sicherte zu: unter `app/Werbung` existiert kein schreibender
| Graph-Aufruf. Der Grund war nicht nur die Abgrenzung zu WP-27 --
| `ads_management` ist die Berechtigung, die im App Review abgelehnt wird,
| und wer sie im Lesepfad braucht, haelt `ads_read` und `business_management`
| mit auf.
|
| **WP-27** bricht das, und zwar an einer einzigen, hier namentlich
| genannten Datei. Der Rest bleibt schreibfrei.
|
| Eine Ausnahmeliste mit einem Eintrag ist eine Regel. Eine ohne Liste ist
| keine -- deshalb steht unten auch die Gegenprobe, dass jeder Eintrag der
| Liste wirklich existiert: ein Name, der ins Leere zeigt, macht die Liste
| stillschweigend laenger als noetig.
|
*/

/**
 * Die einzige Datei, die schreiben darf (WP-27).
 *
 * @var list<string>
 */
const SCHREIBER = ['Graphschreiber.php'];

/**
 * Schreibende HTTP-Aufrufe unter app/Werbung.
 *
 * @return list<string>
 */
function schreibendeAufrufeInWerbung(string $verzeichnis): array
{
    if (! is_dir($verzeichnis)) {
        return [];
    }

    $verstoesse = [];

    foreach (Finder::create()->files()->name('*.php')->in($verzeichnis) as $datei) {
        $inhalt = (string) file_get_contents((string) $datei->getRealPath());

        if (in_array($datei->getFilename(), SCHREIBER, true)) {
            continue;
        }

        // **Nur in Dateien, die ueberhaupt HTTP sprechen.** Die erste Fassung
        // suchte allein nach dem Verb und schlug bei Collection::put() an --
        // ein Test, der beim ersten Lauf falschen Alarm gibt, wird beim
        // zweiten abgeschaltet.
        if (! str_contains($inhalt, 'Http::') && ! str_contains($inhalt, 'PendingRequest')) {
            continue;
        }

        // Auf den Aufruf, nicht auf das Wort: ein Kommentar, der erklaert,
        // warum hier nicht geschrieben wird, ist kein Verstoss.
        if (preg_match('/->\s*(post|put|patch|delete)\s*\(/i', $inhalt) === 1) {
            $verstoesse[] = $datei->getFilename();
        }
    }

    sort($verstoesse);

    return $verstoesse;
}

it('laesst genau eine Datei schreiben', function (): void {
    // Die Gegenprobe zur Ausnahmeliste: ein Name, der ins Leere zeigt, macht
    // sie stillschweigend laenger als noetig.
    expect(SCHREIBER)->toHaveCount(1);

    foreach (SCHREIBER as $name) {
        expect(is_file(app_path('Werbung/Verwaltung/'.$name)))->toBeTrue("{$name} gibt es nicht mehr");
    }
});

it('ruft unter app/Werbung sonst nichts Schreibendes auf', function (): void {
    $verstoesse = schreibendeAufrufeInWerbung(app_path('Werbung'));

    expect($verstoesse)->toBeEmpty(
        'Diese Dateien rufen schreibend auf: '.implode(', ', $verstoesse)
    );
});

it('durchsucht ueberhaupt Dateien', function (): void {
    // Die Gegenprobe: ein Test, der nichts findet, ist immer gruen.
    $dateien = iterator_to_array(Finder::create()->files()->name('*.php')->in(app_path('Werbung')));

    expect(count($dateien))->toBeGreaterThan(5);
});

it('erkennt einen schreibenden Aufruf, wenn es einen gibt', function (): void {
    // Die zweite Gegenprobe: der Sucher findet wirklich etwas.
    $pfad = app_path('Werbung/GegenprobeSchreibzugriff.php');

    file_put_contents($pfad, "<?php\n\nHttp::withToken('x')->post('https://graph.test/v21.0/act_1/campaigns');\n");

    try {
        expect(schreibendeAufrufeInWerbung(app_path('Werbung')))
            ->toContain('GegenprobeSchreibzugriff.php');
    } finally {
        @unlink($pfad);
    }
});
