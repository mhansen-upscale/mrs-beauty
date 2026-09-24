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

/*
|--------------------------------------------------------------------------
| Die Steuerelemente sind gleich hoch
|--------------------------------------------------------------------------
|
| Die ui/-Bauteile stammen aus zwei shadcn-Generationen: Input und Textarea
| aus der alten Fassung (h-10, bg-background, kein Schatten), Select und
| Button aus "new-york" (h-9, bg-transparent, shadow-sm). Vier Pixel
| Unterschied, und zwar in 18 Dateien, die beides in einer Rasterzeile
| mischen -- Postfach, Erscheinungsbild, Abo, Marke, Behandler, Mandant und
| jede Werkzeugzeile einer DataTable.
|
| Das ist der Kern der Meldung "die Eingabefelder sind verrutscht". Es ist
| kein Layoutfehler der Seiten, sondern einer der Bauteile darunter: eine
| Zeile in Input.vue begradigt alle Stellen auf einmal.
|
*/

/**
 * Die Hoehe, die ein Bauteil in seiner Basisklasse festlegt.
 *
 * @return array<string, string>
 */
function steuerhoehen(): array
{
    $quellen = [
        'ui/input/Input.vue' => "/'flex h-(\d+) w-full/",
        'ui/select/SelectTrigger.vue' => "/'flex h-(\d+) w-full/",
        'ui/button/index.ts' => "/default: 'h-(\d+) /",
    ];

    $hoehen = [];

    foreach ($quellen as $datei => $muster) {
        $inhalt = (string) file_get_contents(resource_path('js/components/'.$datei));

        $hoehen[$datei] = preg_match($muster, $inhalt, $treffer) === 1 ? $treffer[1] : 'nicht gefunden';
    }

    return $hoehen;
}

it('gibt Input, Select und Button dieselbe Hoehe', function (): void {
    $hoehen = steuerhoehen();

    $meldung = "Input, SelectTrigger und Button muessen gleich hoch sein.\n";

    foreach ($hoehen as $datei => $hoehe) {
        $meldung .= sprintf("  %-32s h-%s\n", $datei, $hoehe);
    }

    expect(array_unique(array_values($hoehen)))->toHaveCount(1, $meldung);
});

it('liest die Hoehen ueberhaupt aus', function (): void {
    // Ohne diese Zusicherung wuerde die Pruefung oben gruen, sobald alle drei
    // Muster ins Leere greifen -- dann waeren alle Werte "nicht gefunden".
    expect(steuerhoehen())->not->toContain('nicht gefunden');
});

/*
|--------------------------------------------------------------------------
| Keine Tailwind-v4-Schreibweise in einem v3-Projekt
|--------------------------------------------------------------------------
|
| Das Projekt steht auf Tailwind 3.4. Zwei Bauteile tragen Klassen aus v4:
| `outline-hidden` (v3 sagt outline-none) und das nachgestellte Ausrufezeichen
| `decoration-current!` (v3 stellt es voran). Beide erzeugen im Build
| **keine einzige Regel** -- nachgewiesen an public/build/assets/app-*.css.
|
| Der Fehler ist damit vollstaendig unsichtbar: kein Build-Fehler, keine
| Warnung, keine Meldung im Browser. Der Fokusring fehlt einfach, und die
| Unterstreichung bleibt beim Ueberfahren blass.
|
*/

/**
 * Stellen, die eine Klasse in der Schreibweise von Tailwind v4 tragen.
 *
 * @return list<string>
 */
function v4Schreibweisen(): array
{
    // Utilities, die es ausschliesslich in v4 gibt. Die v3-Entsprechung
    // steht daneben, damit die Meldung den Fix gleich mitliefert.
    $nurV4 = [
        'outline-hidden' => 'outline-none',
        'shadow-xs' => 'shadow-sm',
        'rounded-xs' => 'rounded-sm',
        'bg-linear-' => 'bg-gradient-',
    ];

    $verstoesse = [];

    foreach (oberflaechendateien() as $pfad) {
        $inhalt = (string) file_get_contents($pfad);
        $kurz = str_replace(base_path().'/', '', $pfad);

        foreach ($nurV4 as $v4 => $v3) {
            if (str_contains($inhalt, $v4)) {
                $verstoesse[] = sprintf('%s: %s -- in v3 heisst das %s', $kurz, $v4, $v3);
            }
        }

        // Das wichtige Zeichen steht in v3 vorn: !decoration-current, nicht
        // decoration-current!. Geprueft wird nur in statischen class-Werten.
        if (preg_match_all('/\bclass="([^"]*)"/', $inhalt, $treffer) > 0) {
            foreach ($treffer[1] as $klassen) {
                foreach (preg_split('/\s+/', trim($klassen)) ?: [] as $klasse) {
                    if ($klasse !== '' && str_ends_with($klasse, '!')) {
                        $verstoesse[] = sprintf('%s: %s -- in v3 steht das ! vorn', $kurz, $klasse);
                    }
                }
            }
        }
    }

    return array_values(array_unique($verstoesse));
}

it('benutzt keine Tailwind-v4-Schreibweise', function (): void {
    $verstoesse = v4Schreibweisen();

    expect($verstoesse)->toBeEmpty(
        "Diese Klassen erzeugen im v3-Build keine einzige Regel:\n".implode("\n", $verstoesse)
    );
});

it('erkennt die v4-Schreibweise, wenn es sie gibt', function (): void {
    // Die Gegenprobe: beide Muster muessen greifen.
    expect(str_contains('focus-visible:outline-hidden', 'outline-hidden'))->toBeTrue();

    preg_match('/\bclass="([^"]*)"/', '<a class="hover:decoration-current! underline">', $treffer);

    $klassen = preg_split('/\s+/', trim($treffer[1] ?? '')) ?: [];

    expect(array_filter($klassen, fn (string $k): bool => str_ends_with($k, '!')))->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| ml-auto gehoert nicht in eine umbrechende Zeile
|--------------------------------------------------------------------------
|
| `ml-auto` schiebt ein Element an das rechte Ende seiner Zeile. In einer
| Zeile, die nicht umbricht, ist das richtig. In einem `flex-wrap`-Container
| ist es eine Falle: sobald die Zeile auf einem schmalen Schirm umbricht,
| steht das Element allein und rechtsbuendig in einer eigenen Zeile -- mit
| einer grossen Luecke links daneben.
|
| Das ist woertlich die Meldung "die Elemente landen wahllos irgendwo". Es
| traf die Werkzeugzeile jeder Tabelle, den Kontokopf der Werbung, die
| Kachelleiste der Auswertung und die Speicherleiste der Marke.
|
| Das Muster dagegen: `w-full sm:ml-auto sm:w-auto` -- auf dem Handy volle
| Breite, ab sm wieder rechts.
|
*/

/**
 * Stellen, an denen ein Kind mit `ml-auto` in einem `flex-wrap`-Container
 * steht. Die Zuordnung laeuft ueber die Einrueckung: die Vorlagen sind von
 * Prettier formatiert, ein Kind ist tiefer eingerueckt als sein Container.
 *
 * @return list<string>
 */
function mlAutoInUmbruchzeile(): array
{
    $verstoesse = [];

    foreach (vueDateien() as $pfad) {
        $zeilen = file($pfad, FILE_IGNORE_NEW_LINES);

        if ($zeilen === false) {
            continue;
        }

        $kurz = str_replace(base_path().'/', '', $pfad);

        foreach ($zeilen as $nr => $zeile) {
            if (! str_contains($zeile, 'flex-wrap')) {
                continue;
            }

            $tiefe = strlen($zeile) - strlen(ltrim($zeile));

            for ($i = $nr + 1, $ende = count($zeilen); $i < $ende; $i++) {
                if (trim($zeilen[$i]) === '') {
                    continue;
                }

                if (strlen($zeilen[$i]) - strlen(ltrim($zeilen[$i])) <= $tiefe) {
                    break;
                }

                // Nur das nackte ml-auto. sm:ml-auto ist genau der Fix.
                if (preg_match('/(?<![\w:-])ml-auto\b/', $zeilen[$i]) === 1) {
                    $verstoesse[] = sprintf('%s:%d (Container Z.%d)', $kurz, $i + 1, $nr + 1);
                }
            }
        }
    }

    return array_values(array_unique($verstoesse));
}

it('setzt kein nacktes ml-auto in eine umbrechende Zeile', function (): void {
    $verstoesse = mlAutoInUmbruchzeile();

    expect($verstoesse)->toBeEmpty(
        "Nach dem Umbruch steht das Element allein und rechtsbuendig.\n"
        ."Stattdessen `w-full sm:ml-auto sm:w-auto`:\n".implode("\n", $verstoesse)
    );
});

it('erkennt ml-auto in einer Umbruchzeile, wenn es sie gibt', function (): void {
    // Die Gegenprobe: Container und Kind, das Kind tiefer eingerueckt.
    expect(preg_match('/(?<![\w:-])ml-auto\b/', '    <Button class="ml-auto">X</Button>'))->toBe(1)
        ->and(preg_match('/(?<![\w:-])ml-auto\b/', '    <Button class="sm:ml-auto">X</Button>'))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| DataTable wird mit den Namen aufgerufen, die es hat
|--------------------------------------------------------------------------
|
| `:such-felder` sieht aus wie `:suchfelder` und ist es nicht. Vue macht aus
| einem Bindestrichnamen einen Binnenmajuskelnamen: `such-felder` wird zu
| `suchFelder`. Das Prop heisst aber durchgehend klein. Der Wert faellt damit
| als gewoehnliches Attribut auf das Wurzelelement durch, `suchfelder` bleibt
| leer -- und weil DataTable die Werkzeugzeile nur bei Suchfeldern oder einem
| #werkzeuge-Slot rendert, verschwand auf der Auswertung die ganze Zeile
| samt Suchfeld. Ohne Meldung, ohne Warnung.
|
*/

/**
 * Aufrufe von <DataTable>, die ein Attribut benutzen, das es nicht gibt.
 *
 * @return list<string>
 */
function unbekannteDataTableProps(): array
{
    $bauteil = (string) file_get_contents(resource_path('js/components/DataTable.vue'));

    // Der Rumpf von defineProps<{ ... }>() -- daraus die Namen vor dem
    // Doppelpunkt, optionales Fragezeichen abgeschnitten.
    preg_match('/defineProps<\{(.*?)\}>\(\)/s', $bauteil, $rumpf);

    preg_match_all('/^\s*(\w+)\??:/m', $rumpf[1] ?? '', $treffer);

    // Attribute, die jedes Bauteil vertraegt.
    $erlaubt = array_merge($treffer[1], ['class', 'style', 'key', 'ref', 'id']);

    $verstoesse = [];

    foreach (vueDateien() as $pfad) {
        if (basename($pfad) === 'DataTable.vue') {
            continue;
        }

        $inhalt = (string) file_get_contents($pfad);
        $kurz = str_replace(base_path().'/', '', $pfad);

        if (preg_match_all('/<DataTable\b([^>]*)>/s', $inhalt, $aufrufe) === 0) {
            continue;
        }

        foreach ($aufrufe[1] as $attribute) {
            preg_match_all('/(?:^|\s):?([a-z][\w-]*)=/i', $attribute, $namen);

            foreach ($namen[1] as $name) {
                // v-bind, v-if und Ereignisse gehen DataTable nichts an.
                if (str_starts_with($name, 'v-') || str_starts_with($name, 'data-')) {
                    continue;
                }

                $binnen = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $name))));

                if (! in_array($name, $erlaubt, true) && ! in_array($binnen, $erlaubt, true)) {
                    $verstoesse[] = sprintf('%s: :%s (gemeint ist wohl :%s)', $kurz, $name, strtolower($binnen));
                }
            }
        }
    }

    return array_values(array_unique($verstoesse));
}

it('ruft DataTable nur mit vorhandenen Props auf', function (): void {
    $verstoesse = unbekannteDataTableProps();

    expect($verstoesse)->toBeEmpty(
        "Diese Attribute fallen stillschweigend auf das Wurzelelement durch:\n".implode("\n", $verstoesse)
    );
});

it('findet ueberhaupt DataTable-Aufrufe', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    $mitTabelle = array_filter(
        vueDateien(),
        fn (string $pfad): bool => basename($pfad) !== 'DataTable.vue'
            && str_contains((string) file_get_contents($pfad), '<DataTable')
    );

    expect(count($mitTabelle))->toBeGreaterThanOrEqual(10);
});

/*
|--------------------------------------------------------------------------
| Ein Knopf, der nur ein Symbol zeigt, ist quadratisch und hat einen Namen
|--------------------------------------------------------------------------
|
| `size="sm"` ist `h-8 px-3`: mit einem einzelnen Symbol darin wird daraus
| ein Rechteck, das neben den quadratischen Zeilenaktionen der Nachbarzeile
| sichtbar aus der Reihe faellt. Das ist der zweite Teil der Meldung "die
| Knoepfe sind unterschiedlich gross".
|
| Dazu kommt der Name: ein Knopf ohne Text hat ohne `aria-label` oder
| `sr-only` ueberhaupt keine Beschriftung. `title` reicht nicht -- das ist
| ein Hinweis fuer die Maus, kein zugaenglicher Name, und auf einem
| Beruehrungsbildschirm gibt es kein Ueberfahren.
|
| Fuer Zeilenaktionen gibt es AktionsButton; es setzt beides von sich aus.
|
*/

/**
 * Knoepfe ohne Text, denen size="icon" oder eine Beschriftung fehlt.
 *
 * @return list<string>
 */
function symbolknoepfeOhneMass(): array
{
    $verstoesse = [];

    foreach (vueDateien() as $pfad) {
        // Das Bauteil selbst setzt beides und ist der empfohlene Weg.
        if (basename($pfad) === 'AktionsButton.vue') {
            continue;
        }

        $inhalt = (string) file_get_contents($pfad);
        $kurz = str_replace(base_path().'/', '', $pfad);

        if (preg_match_all('/<Button\b([^>]*)>(.*?)<\/Button>/s', $inhalt, $treffer, PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($treffer as [$ganz, $attribute, $rumpf]) {
            // Nur Knoepfe, in denen kein sichtbarer Text steht.
            if (trim(strip_tags($rumpf)) !== '') {
                continue;
            }

            $mangel = [];

            if (! str_contains($attribute, 'size="icon"')) {
                $mangel[] = 'ohne size="icon"';
            }

            if (! str_contains($attribute, 'aria-label') && ! str_contains($rumpf, 'sr-only')) {
                $mangel[] = 'ohne Beschriftung';
            }

            if ($mangel !== []) {
                $verstoesse[] = sprintf('%s: %s -- %s', $kurz, implode(', ', $mangel), trim(explode("\n", $ganz)[0]));
            }
        }
    }

    return array_values(array_unique($verstoesse));
}

it('macht Symbolknoepfe quadratisch und benennt sie', function (): void {
    $verstoesse = symbolknoepfeOhneMass();

    expect($verstoesse)->toBeEmpty(
        "Ein Knopf ohne Text braucht size=\"icon\" und aria-label (oder AktionsButton):\n"
        .implode("\n", $verstoesse)
    );
});

it('erkennt den unbenannten Symbolknopf, wenn es ihn gibt', function (): void {
    // Die Gegenprobe: das Muster trennt Symbol- von Textknopf.
    $rumpf = function (string $auszeichnung): string {
        preg_match('/<Button\b([^>]*)>(.*?)<\/Button>/s', $auszeichnung, $treffer);

        return trim(strip_tags($treffer[2] ?? ''));
    };

    expect($rumpf('<Button size="sm"><Trash2 /></Button>'))->toBe('')
        ->and($rumpf('<Button size="sm"><Trash2 />Loeschen</Button>'))->toBe('Loeschen');
});

/*
|--------------------------------------------------------------------------
| Kein Menuepunkt ohne Erklaerung
|--------------------------------------------------------------------------
|
| Die Einfuehrung laeuft ueber die Punkte, die die Seitenleiste tatsaechlich
| rendert -- und nimmt genau die mit, zu denen ein Satz hinterlegt ist. Wer
| einen Menuepunkt ergaenzt und den Satz vergisst, merkt nichts: die Fuehrung
| ueberspringt ihn stillschweigend.
|
| Deshalb hier und nicht als Absatz in einem Dokument.
|
*/

/**
 * Adressen, die in der Seitenleiste stehen, aber keinen Einfuehrungstext
 * haben.
 *
 * @return list<string>
 */
function menuepunkteOhneEinfuehrung(): array
{
    $menue = (string) file_get_contents(resource_path('js/components/AppSidebar.vue'));
    $texte = (string) file_get_contents(resource_path('js/composables/useEinfuehrung.ts'));

    preg_match_all("/href: '([^']+)'/", $menue, $imMenue);
    preg_match_all("/^\s*'([^']+)':/m", $texte, $erklaert);

    $vorhanden = $erklaert[1];

    return array_values(array_unique(array_filter(
        $imMenue[1],
        fn (string $adresse): bool => ! in_array($adresse, $vorhanden, true),
    )));
}

it('erklaert jeden Menuepunkt in der Einfuehrung', function (): void {
    $fehlend = menuepunkteOhneEinfuehrung();

    expect($fehlend)->toBeEmpty(
        "Diese Menuepunkte ueberspringt die Fuehrung stillschweigend:\n".implode("\n", $fehlend)
    );
});

it('findet ueberhaupt Menuepunkte', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    $menue = (string) file_get_contents(resource_path('js/components/AppSidebar.vue'));

    expect(preg_match_all("/href: '([^']+)'/", $menue))->toBeGreaterThanOrEqual(15);
});

/*
|--------------------------------------------------------------------------
| Wer einen Warteschlangenzustand zeigt, laedt ihn auch nach
|--------------------------------------------------------------------------
|
| Jeder schreibende Zugriff auf Meta laeuft ueber eine Queue (Regel 4). Die
| Antwort auf den Klick sagt deshalb nur, dass der Auftrag angenommen wurde.
| Wer den Zustand danach anzeigt und nicht nachlaedt, zeigt ihn fuer immer
| falsch: die Kampagne steht laengst bei Meta, die Seite sagt weiter
| "Wird uebertragen" -- bis jemand den Browser neu laedt.
|
| Gemeldet am 24.09.2026. Vorher stand das Nachladen nur auf der
| Anzeigenseite, und auch dort nur fuer entstehende Grafiken.
|
*/

/**
 * Seiten, die einen Uebertragungszustand zeigen, ohne ihn nachzuladen.
 *
 * @return list<string>
 */
function seitenOhneNachladen(): array
{
    $verstoesse = [];

    foreach (vueDateien() as $pfad) {
        $inhalt = (string) file_get_contents($pfad);

        // Nur Seiten, die den Zustand einer Uebertragung auswerten -- ein
        // 'pending' am Terminstatus ist etwas anderes.
        if (! str_contains($inhalt, "'pending'") || ! str_contains(strtolower($inhalt), 'uebertragung')) {
            continue;
        }

        if (! str_contains($inhalt, 'useNachladen')) {
            $verstoesse[] = str_replace(base_path().'/', '', $pfad);
        }
    }

    return $verstoesse;
}

it('laedt jeden gezeigten Uebertragungszustand nach', function (): void {
    $verstoesse = seitenOhneNachladen();

    expect($verstoesse)->toBeEmpty(
        "Diese Seiten zeigen einen Warteschlangenzustand, der nur beim Neuladen des Browsers weiterspringt:\n"
        .implode("\n", $verstoesse)
    );
});

it('findet ueberhaupt Seiten mit einem Uebertragungszustand', function (): void {
    // Ohne diese Zusicherung koennte die Pruefung oben leer durchlaufen.
    $gefunden = 0;

    foreach (vueDateien() as $pfad) {
        $inhalt = (string) file_get_contents($pfad);

        if (str_contains($inhalt, "'pending'") && str_contains(strtolower($inhalt), 'uebertragung')) {
            $gefunden++;
        }
    }

    expect($gefunden)->toBeGreaterThanOrEqual(2);
});

it('erkennt die Seite ohne Nachladen, wenn es sie gibt', function (): void {
    // Die Gegenprobe: das Muster trennt den Uebertragungszustand vom
    // Terminstatus, der ebenfalls 'pending' kennt.
    $traegtZustand = fn (string $inhalt): bool => str_contains($inhalt, "'pending'")
        && str_contains(strtolower($inhalt), 'uebertragung');

    expect($traegtZustand("zeile.uebertragung === 'pending'"))->toBeTrue()
        ->and($traegtZustand("termin.status === 'pending'"))->toBeFalse();
});
