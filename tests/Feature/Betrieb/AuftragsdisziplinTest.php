<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| WP-33 -- was jeder Auftrag sagen muss
|--------------------------------------------------------------------------
|
| Zwei Zusagen, die sich nicht aus dem Code ergeben, sondern aus der
| Betriebsweise dieses Projekts:
|
| - **Warteschlange**: vier gibt es (realtime, default, sync, maintenance),
|   und ein Auftrag ohne Angabe landet in der falschen. Eine Antwort an einen
|   Menschen, der gerade schreibt, hat Vorrang vor einem Kalenderabgleich.
|
| - **afterCommit**: das Projekt hat `after_commit = false`. Ein Auftrag, der
|   in einer Transaktion entsteht und das nicht selbst sagt, findet seine
|   Zeile nicht -- ein schnellerer Arbeiter ist vor dem Commit da.
|
| Dazu eine dritte, die erst beim Schreiben dieses Tests auffiel: **jede
| benutzte Warteschlange braucht ein Profil.** Wer `->onQueue('berichte')`
| schreibt und keinen Arbeiter dafuer hat, bekommt einen Auftrag, der nie
| laeuft -- und niemand sieht es (Regel 4).
|
| **Die Zahl der Versuche steht bewusst nicht hier.** Sie gehoert zum
| Arbeiter (config/warteschlangen.php) und nicht zum Auftrag: `sync` und
| `maintenance` laufen mit einem Versuch, `realtime` und `default` mit drei.
| Ein Auftrag darf das ueberschreiben, muss aber nicht.
|
*/

/** @return list<string> */
function auftragsdateien(): array
{
    $dateien = [];

    foreach (Finder::create()->files()->name('*.php')->in(app_path('Jobs')) as $datei) {
        $dateien[] = (string) $datei->getRealPath();
    }

    sort($dateien);

    return $dateien;
}

/**
 * Auftraege, in denen das Muster weder selbst noch in ihrer Basisklasse steht.
 *
 * **Vererbung zaehlt.** Die Kalenderauftraege erben Warteschlange und
 * afterCommit von einer gemeinsamen Basisklasse -- ein Test, der nur die
 * eigene Datei liest, haette sie zu Unrecht angezeigt.
 *
 * @return list<string>
 */
function auftraegeOhne(string $muster): array
{
    $verstoesse = [];
    $inhalte = [];

    foreach (auftragsdateien() as $pfad) {
        $inhalte[basename($pfad, '.php')] = (string) file_get_contents($pfad);
    }

    foreach ($inhalte as $klasse => $inhalt) {
        if (! str_contains($inhalt, 'implements ShouldQueue')) {
            continue;
        }

        if (preg_match($muster, $inhalt) === 1) {
            continue;
        }

        // Die Basisklasse, falls es eine im selben Verzeichnis gibt.
        preg_match('/extends\s+(\w+)/', $inhalt, $treffer);
        $basis = $treffer[1] ?? null;

        if (is_string($basis) && isset($inhalte[$basis]) && preg_match($muster, $inhalte[$basis]) === 1) {
            continue;
        }

        $verstoesse[] = $klasse.'.php';
    }

    sort($verstoesse);

    return $verstoesse;
}

/**
 * Jede Warteschlange, die ein Auftrag nennt.
 *
 * @return list<string>
 */
function genutzteWarteschlangen(): array
{
    $namen = [];

    foreach (auftragsdateien() as $pfad) {
        preg_match_all("/onQueue\('([a-z_]+)'\)/", (string) file_get_contents($pfad), $treffer);

        foreach ($treffer[1] as $name) {
            $namen[$name] = true;
        }
    }

    return array_keys($namen);
}

it('findet ueberhaupt Auftraege', function (): void {
    // Die Gegenprobe: ein Test, der nichts durchsucht, ist immer gruen.
    expect(count(auftragsdateien()))->toBeGreaterThan(8);
});

it('nennt jeder Auftrag seine Warteschlange', function (): void {
    $verstoesse = auftraegeOhne('/onQueue\(/');

    expect($verstoesse)->toBeEmpty('Diese Auftraege nennen keine Warteschlange: '.implode(', ', $verstoesse));
});

it('laeuft jeder Auftrag nach dem Commit', function (): void {
    $verstoesse = auftraegeOhne('/afterCommit\(\)/');

    expect($verstoesse)->toBeEmpty('Diese Auftraege laufen nicht nach dem Commit: '.implode(', ', $verstoesse));
});

it('hat jede benutzte Warteschlange ein Profil', function (): void {
    // Ein Auftrag auf einer Warteschlange ohne Arbeiter laeuft nie -- und
    // niemand sieht es.
    //
    // **Was dieser Test nicht mehr leisten kann.** Bis zum 22.09.2026 hing
    // das Profil an einem Horizon-Supervisor, der die Arbeiter auch startete
    // -- ein Eintrag hier war zugleich die Zusage, dass jemand abholt. Auf
    // der verwalteten Warteschlange von Laravel Cloud stehen die Arbeiter in
    // der Oberflaeche des Anbieters. Dieser Test prueft seitdem nur noch, dass
    // jede benutzte Warteschlange **beschrieben** ist; ob sie **bedient**
    // wird, kann allein die Laufzeit sagen -- siehe WarteschlangenTest.
    $profile = array_keys((array) config('warteschlangen.profile', []));
    $genutzte = genutzteWarteschlangen();

    expect($genutzte)->not->toBeEmpty();

    foreach ($genutzte as $name) {
        expect($profile)->toContain($name);
    }
});

it('erkennt einen Auftrag ohne Warteschlange, wenn es einen gibt', function (): void {
    // Die zweite Gegenprobe: der Sucher findet wirklich etwas.
    $pfad = app_path('Jobs/GegenprobeOhneQueue.php');

    file_put_contents($pfad, "<?php\n\nnamespace App\\Jobs;\n\nclass GegenprobeOhneQueue implements ShouldQueue\n{\n}\n");

    try {
        expect(auftraegeOhne('/onQueue\(/'))->toContain('GegenprobeOhneQueue.php');
    } finally {
        @unlink($pfad);
    }
});

/*
|--------------------------------------------------------------------------
| Was hier stand und warum es weg ist
|--------------------------------------------------------------------------
|
| Zwei Tests hielten fest, dass `horizon.environments` jede Umgebung und darin
| jede Warteschlange kennt -- ein Supervisor ohne Umgebung ist keiner, und am
| 22.09.2026 stand Staging genau daran still.
|
| Mit dem Wechsel auf die verwaltete Warteschlange von Laravel Cloud gibt es
| keine `environments` mehr. Die Arbeiter werden in der Oberflaeche des
| Anbieters eingerichtet, ausserhalb dieses Repositorys. **Damit ist die Zusage
| nicht mehr statisch pruefbar** -- und das ist ein Verlust, kein Aufraeumen.
|
| Der Ersatz kann nur zur Laufzeit greifen und steht in
| tests/Feature/Betrieb/WarteschlangenTest.php: liegt etwas, und hat seit einer
| Frist niemand abgeholt, meldet die Betriebslage Stillstand.
|
*/
