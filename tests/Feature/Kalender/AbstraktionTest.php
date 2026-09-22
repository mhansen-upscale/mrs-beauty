<?php

declare(strict_types=1);

use App\Enums\CalendarProvider;
use App\Kalender\Kalenderdienst;
use App\Kalender\Kalenderdienste;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| WP-15, Abnahmekriterien 25 und 26 -- die Abstraktion haelt, was sie soll
|--------------------------------------------------------------------------
|
| docs/integrationen/kalender.md verlangt die Reihenfolge "erst beide
| umsetzen, dann abstrahieren". Ob das gelungen ist, laesst sich nicht an der
| Absicht ablesen, sondern nur daran, dass die Fachlogik keinen Anbieter mehr
| kennt. Genau das steht hier.
|
*/

/**
 * Dateien der Fachlogik, die aus einem Anbieter-Namensraum importieren.
 *
 * @param  list<string>  $ausnahmen
 * @return list<string>
 */
function fachlogikMitAnbieter(array $ausnahmen = ['Kalenderdienste.php']): array
{
    $verstoesse = [];

    $verzeichnisse = [app_path('Kalender'), app_path('Jobs')];

    foreach (Finder::create()->files()->name('*.php')->depth('== 0')->in($verzeichnisse) as $datei) {
        $pfad = $datei->getRealPath();

        if (in_array(basename($pfad), $ausnahmen, true)) {
            continue;
        }

        $inhalt = (string) file_get_contents($pfad);

        if (preg_match('/use\s+App\\\\Kalender\\\\(Google|Microsoft)\\\\/', $inhalt) === 1) {
            $verstoesse[] = str_replace(base_path().'/', '', $pfad);
        }
    }

    sort($verstoesse);

    return $verstoesse;
}

it('haelt die Anbieter aus der Fachlogik heraus', function (): void {
    $verstoesse = fachlogikMitAnbieter();

    expect($verstoesse)->toBeEmpty(
        "Diese Dateien der Fachlogik greifen auf einen Anbieter zu.\n"
        ."Der Weg dorthin fuehrt ueber Kalenderdienste:\n".implode("\n", $verstoesse)
    );
});

it('erkennt einen Anbieterzugriff, wenn es ihn gibt', function (): void {
    // Die Gegenprobe: ohne Ausnahmeliste muss die Fabrik selbst auffallen --
    // sie ist die eine Stelle, die beide Anbieter kennen **darf**.
    expect(fachlogikMitAnbieter(ausnahmen: []))->toContain('app/Kalender/Kalenderdienste.php');
});

it('loest jeden Anbieter zu einem Dienst auf', function (): void {
    // Ein neuer Fall im Enum ohne Umsetzung faellt hier auf und nicht erst,
    // wenn jemand ihn verbinden will.
    foreach (CalendarProvider::cases() as $anbieter) {
        $dienst = app(Kalenderdienste::class)->fuer($anbieter);

        expect($dienst)->toBeInstanceOf(Kalenderdienst::class)
            ->and($dienst->anbieter())->toBe($anbieter);
    }
});

it('haelt die Fachlogik ueberhaupt in App\Kalender', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    $dateien = iterator_to_array(
        Finder::create()->files()->name('*.php')->depth('== 0')->in(app_path('Kalender'))
    );

    expect(count($dateien))->toBeGreaterThanOrEqual(10);
});
