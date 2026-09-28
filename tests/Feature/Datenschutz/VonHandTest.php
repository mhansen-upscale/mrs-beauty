<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Entscheidung C19 -- Fristen werden von Hand durchgesetzt
|--------------------------------------------------------------------------
|
| Ein Lauf, der zu viel loescht, ist nicht rueckholbar. Deshalb zeigt der
| taegliche Lauf nur, was faellig ist; geloescht wird in der Praxis per
| "Jetzt durchsetzen", ohne Praxis per `mrs:aufbewahrung --scharf`
| (entschieden am 28.09.2026).
|
| Bis dahin versprachen Startseite, Datenschutzerklaerung und die Seite der
| Demo-Anfragen, das System loesche "von selbst" oder "automatisch" -- und
| nichts tat es. Ein Versprechen in einer Datenschutzerklaerung, das der
| Betrieb nicht haelt, ist schlimmer als eines, das nie gegeben wurde.
|
*/

it('plant den taeglichen Lauf nur als Vorschau', function (): void {
    Artisan::call('schedule:list');

    $zeilen = array_values(array_filter(
        explode("\n", Artisan::output()),
        fn (string $zeile): bool => str_contains($zeile, 'mrs:aufbewahrung'),
    ));

    expect($zeilen)->toHaveCount(1)
        ->and($zeilen[0])->not->toContain('--scharf');
});

/**
 * Stellen in der Oberflaeche, die ein Loeschen "von selbst" oder
 * "automatisch" versprechen.
 *
 * Ueber Zeilen hinweg: ein Satz im Template bricht um, wo der Formatierer
 * will. Ein Punkt beendet die Suche, damit zwei Saetze nicht zu einem
 * Versprechen verschmelzen.
 *
 * @return list<string>
 */
function loeschversprechen(string $inhalt): array
{
    preg_match_all(
        '/(?:lösch|gelöscht)[^.<>]{0,80}(?:von selbst|automatisch)|(?:von selbst|automatisch)[^.<>]{0,60}(?:lösch|gelöscht)/iu',
        $inhalt,
        $treffer,
    );

    return array_map(fn (string $stelle): string => (string) preg_replace('/\s+/', ' ', $stelle), $treffer[0]);
}

it('verspricht in keinem Text ein Loeschen, das niemand ausloest', function (): void {
    $verstoesse = [];

    foreach (Finder::create()->files()->name(['*.vue', '*.ts'])->in(resource_path('js')) as $datei) {
        foreach (loeschversprechen((string) file_get_contents($datei->getRealPath())) as $stelle) {
            $verstoesse[] = $datei->getRelativePathname().': '.$stelle;
        }
    }

    expect($verstoesse)->toBeEmpty(
        "Diese Texte versprechen, was der Lauf nicht tut (C19):\n".implode("\n", $verstoesse)
    );
});

it('erkennt ein solches Versprechen, auch ueber einen Zeilenumbruch', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    expect(loeschversprechen('Wir löschen die Angaben nach {{ n }} Monaten'."\n".'                automatisch, früher'))->toHaveCount(1)
        ->and(loeschversprechen('Was nicht mehr gebraucht wird, löscht es von selbst.'))->toHaveCount(1)
        ->and(loeschversprechen('Ein täglicher Lauf zeigt, was fällig ist; gelöscht wird, wenn Sie es auslösen.'))->toBeEmpty();
});
