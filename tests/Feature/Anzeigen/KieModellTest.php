<?php

declare(strict_types=1);

use App\Anzeigen\Bildsatz;
use App\Anzeigen\KieAi\KieModell;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| WP-31 -- die Anbindung an kie.ai
|--------------------------------------------------------------------------
|
| **Gegen die dokumentierte Form**, nicht gegen eine geratene. Die erste
| Fassung dieser Anbindung lag an vier Stellen daneben und ist erst beim
| ersten echten Aufruf aufgefallen -- weil kein Test die Form festhielt.
|
| Belegt: docs.kie.ai, „Get Task Details" und die Modellseiten unter /market.
|
| Seit WP-31b nimmt das Modell je Format einen Auftrag und liefert einen
| Bildsatz: die Bilder, die ankamen, und die Gruende fuer die anderen.
|
*/

beforeEach(function (): void {
    config()->set('services.kie.key', 'geheim');
    config()->set('services.kie.url', 'https://kie.test/api/v1/jobs');
    config()->set('services.kie.model', 'google/nano-banana');
    config()->set('services.kie.poll_ms', 0);
});

/** Ein Quadrat -- die meisten Faelle hier brauchen nur eines. */
function einQuadrat(string $auftrag = 'Egal'): Bildsatz
{
    return app(KieModell::class)->erzeuge(['1x1' => $auftrag]);
}

/**
 * kie.ai, wie es fuer mehrere Auftraege gleichzeitig antwortet: jede
 * Auftragskennung traegt ihr Seitenverhaeltnis, jede Bildadresse ihre
 * Kennung.
 *
 * @param  array<string, string>  $zustaende  Zustand je Auftragskennung, sonst success
 */
function kieMitMehrerenAuftraegen(array $zustaende = []): void
{
    Http::fake(function (Request $anfrage) use ($zustaende) {
        if (str_contains($anfrage->url(), 'createTask')) {
            $eingabe = (array) ($anfrage->data()['input'] ?? []);
            $verhaeltnis = is_string($eingabe['aspect_ratio'] ?? null) ? $eingabe['aspect_ratio'] : 'auto';

            return Http::response(['code' => 200, 'data' => ['taskId' => 'task_'.$verhaeltnis]]);
        }

        if (str_contains($anfrage->url(), 'recordInfo')) {
            parse_str((string) parse_url($anfrage->url(), PHP_URL_QUERY), $abfrage);
            $kennung = is_string($abfrage['taskId'] ?? null) ? $abfrage['taskId'] : '';
            $zustand = $zustaende[$kennung] ?? 'success';

            return Http::response(['code' => 200, 'data' => $zustand === 'success'
                ? ['state' => 'success', 'resultJson' => '{"resultUrls":["https://bilder.test/'.rawurlencode($kennung).'.png"]}']
                : ['state' => $zustand, 'failMsg' => 'blocked by policy']]);
        }

        return Http::response('bilddaten', 200, ['Content-Type' => 'image/png']);
    });
}

/** @return list<array<string, mixed>> die `input`-Bloecke aller angelegten Auftraege */
function angelegteAuftraege(): array
{
    return array_values(Http::recorded()
        ->filter(fn (array $paar): bool => str_contains($paar[0]->url(), 'createTask'))
        ->map(fn (array $paar): array => (array) ($paar[0]->data()['input'] ?? []))
        ->all());
}

it('schickt den Auftrag in der dokumentierten Form', function (): void {
    Http::fake([
        'kie.test/*/createTask' => Http::response(['code' => 200, 'data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['code' => 200, 'data' => [
            'state' => 'success',
            'resultJson' => '{"resultUrls":["https://bilder.test/eins.png"]}',
        ]]),
        'bilder.test/*' => Http::response('bilddaten', 200, ['Content-Type' => 'image/png']),
    ]);

    $bild = einQuadrat('Ein ruhiger Empfangsbereich.')->bilder['1x1'];

    expect($bild->mime)->toBe('image/png')
        ->and($bild->inhalt)->toBe('bilddaten');

    Http::assertSent(function ($anfrage): bool {
        if (! str_contains($anfrage->url(), 'createTask')) {
            return false;
        }

        $daten = $anfrage->data();

        // **Der Auftrag steht unter `input`**, nicht oben -- daran ist die
        // erste Fassung gescheitert.
        return $anfrage->method() === 'POST'
            && ($daten['model'] ?? null) === 'google/nano-banana'
            && ($daten['input']['prompt'] ?? null) === 'Ein ruhiger Empfangsbereich.'
            && ! array_key_exists('prompt', $daten);
    });
});

it('liest die Bildadresse aus resultJson, nicht aus einem Feld', function (): void {
    // `data.resultJson` ist eine **Zeichenkette, die JSON enthaelt**. Wer sie
    // als Objekt liest, findet nichts -- und bekommt keinen Hinweis darauf,
    // warum.
    Http::fake([
        'kie.test/*/createTask' => Http::response(['data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::sequence()
            ->push(['data' => ['state' => 'queuing']])
            ->push(['data' => ['state' => 'generating']])
            ->push(['data' => ['state' => 'success', 'resultJson' => '{"resultUrls":["https://bilder.test/zwei.webp"]}']]),
        'bilder.test/*' => Http::response('bilddaten', 200, ['Content-Type' => 'image/webp']),
    ]);

    expect(einQuadrat('Ein Wartebereich.')->bilder['1x1']->mime)->toBe('image/webp');
});

it('nennt in der Meldung, was zurueckkam', function (): void {
    // **Der Fund, der diese Runde ausgeloest hat.** Eine Meldung, die
    // verschweigt, was zurueckkam, laesst nur raten.
    Http::fake([
        'kie.test/*/createTask' => Http::response(['code' => 402, 'msg' => 'Insufficient credits'], 402),
    ]);

    $satz = einQuadrat();

    expect($satz->bilder)->toBe([])
        ->and($satz->fehler['1x1'])->toContain('Insufficient credits');
});

it('nennt auch bei fehlender Auftragskennung die Antwort', function (): void {
    Http::fake([
        'kie.test/*/createTask' => Http::response(['code' => 200, 'msg' => 'success', 'data' => null]),
    ]);

    expect(einQuadrat()->fehler['1x1'])->toContain('keine Auftragskennung')
        ->toContain('HTTP 200')
        ->toContain('success');
});

it('gibt einen fehlgeschlagenen Auftrag nicht als Bild aus', function (): void {
    Http::fake([
        'kie.test/*/createTask' => Http::response(['data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['data' => ['state' => 'fail', 'failMsg' => 'blocked by policy']]),
    ]);

    $satz = einQuadrat();

    expect($satz->bilder)->toBe([])
        ->and($satz->fehler['1x1'])->toContain('blocked by policy');
});

it('bricht ab, statt endlos zu fragen', function (): void {
    // Ein Auftrag, der nie fertig wird, haelt sonst die Warteschlange an.
    config()->set('services.kie.max_polls', 3);

    Http::fake([
        'kie.test/*/createTask' => Http::response(['data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['data' => ['state' => 'generating']]),
    ]);

    expect(einQuadrat()->fehler['1x1'])->toContain('nicht rechtzeitig');
});

it('nimmt kein SVG entgegen', function (): void {
    // Wie beim Logo in WP-07: eine SVG-Datei kann ein Skript enthalten.
    Http::fake([
        'kie.test/*/createTask' => Http::response(['data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['data' => [
            'state' => 'success',
            'resultJson' => '{"resultUrls":["https://bilder.test/drei.svg"]}',
        ]]),
        'bilder.test/*' => Http::response('<svg/>', 200, ['Content-Type' => 'image/svg+xml']),
    ]);

    expect(einQuadrat()->fehler['1x1'])->toContain('image/svg+xml');
});

it('gilt ohne Schluessel als nicht angebunden', function (): void {
    config()->set('services.kie.key', null);

    expect(app(KieModell::class)->angebunden())->toBeFalse();
});

/**
 * **Jedes Modell hat seine eigenen Eingabefelder.**
 *
 * GPT Image 2 nimmt `aspect_ratio` und `resolution`, nano-banana hiess das
 * Seitenverhaeltnis anders. Ein fest verdrahtetes Feld heisst: beim
 * Modellwechsel schickt das Produkt still etwas, das niemand liest -- und
 * das Bild kommt im falschen Format zurueck, ohne dass jemand einen Fehler
 * sieht.
 *
 * Fundstelle: kie.ai/gpt-image-2, Abschnitt API/Input.
 */
it('schickt die Eingabefelder des eingestellten Modells mit', function (): void {
    config()->set('services.kie.model', 'gpt-image-2-text-to-image');
    config()->set('services.kie.input', ['resolution' => '2K']);
    config()->set('services.kie.formate', ['1x1' => ['aspect_ratio' => '1:1']]);

    kieMitMehrerenAuftraegen();

    einQuadrat('Ein ruhiger Empfangsbereich.');

    Http::assertSent(function ($anfrage): bool {
        if (! str_contains($anfrage->url(), 'createTask')) {
            return false;
        }

        $daten = $anfrage->data();

        return ($daten['model'] ?? null) === 'gpt-image-2-text-to-image'
            && ($daten['input']['aspect_ratio'] ?? null) === '1:1'
            && ($daten['input']['resolution'] ?? null) === '2K'
            && ($daten['input']['prompt'] ?? null) === 'Ein ruhiger Empfangsbereich.';
    });
});

it('schickt kein Eingabefeld, das nicht konfiguriert ist', function (): void {
    // Ein Modell, das `resolution` nicht kennt, soll es auch nicht bekommen.
    config()->set('services.kie.input', []);
    config()->set('services.kie.formate', ['1x1' => ['aspect_ratio' => '1:1']]);

    kieMitMehrerenAuftraegen();

    einQuadrat('Ein ruhiger Empfangsbereich.');

    expect(angelegteAuftraege())->toHaveCount(1)
        ->and(angelegteAuftraege()[0])->not->toHaveKey('resolution');
});

/*
|--------------------------------------------------------------------------
| WP-31b -- ein Auftrag je Format
|--------------------------------------------------------------------------
*/

/**
 * **4:5 nimmt GPT Image 2 in 2K nicht an** ("for 2K resolution, the
 * following aspect ratios are not supported: 5:4, 4:5, 3:1, 1:3, and 9:21",
 * docs.kie.ai). Das Hochformat geht deshalb in 4K hinaus.
 *
 * Geprueft wird die ausgelieferte Konfiguration, nicht eine im Test
 * gesetzte: sie ist es, die sonst im falschen Format zurueckkaeme.
 */
it('schickt jedem Format sein Seitenverhaeltnis, das Hochformat in 4K', function (): void {
    kieMitMehrerenAuftraegen();

    $satz = app(KieModell::class)->erzeuge(['1x1' => 'Quadrat', '4x5' => 'Hochformat', '9x16' => 'Stories']);

    expect(array_keys($satz->bilder))->toBe(['1x1', '4x5', '9x16'])
        ->and($satz->fehler)->toBe([]);

    $auftraege = collect(angelegteAuftraege())->keyBy('prompt');

    expect($auftraege['Quadrat'])->toMatchArray(['aspect_ratio' => '1:1', 'resolution' => '2K'])
        ->and($auftraege['Hochformat'])->toMatchArray(['aspect_ratio' => '4:5', 'resolution' => '4K'])
        ->and($auftraege['Stories'])->toMatchArray(['aspect_ratio' => '9:16', 'resolution' => '2K']);
});

/**
 * **Ohne Seitenverhaeltnis kein Auftrag.** Das Modell waehlte sonst `auto`,
 * und die Grafik kaeme im falschen Format zurueck -- bezahlt und ohne
 * Fehler.
 */
it('beauftragt kein Format, fuer das kein Seitenverhaeltnis eingestellt ist', function (): void {
    config()->set('services.kie.formate', ['1x1' => ['aspect_ratio' => '1:1']]);

    kieMitMehrerenAuftraegen();

    $satz = app(KieModell::class)->erzeuge(['1x1' => 'Quadrat', '9x16' => 'Stories']);

    expect(array_keys($satz->bilder))->toBe(['1x1'])
        ->and($satz->fehler['9x16'])->toContain('Seitenverhältnis')
        ->and(angelegteAuftraege())->toHaveCount(1);
});

/**
 * **Gleichzeitig, nicht nacheinander.** Nacheinander waeren es bis zu neun
 * Minuten, und der Auftrag in der Warteschlange hat zehn.
 */
it('legt alle Auftraege an, bevor es den ersten abfragt', function (): void {
    kieMitMehrerenAuftraegen();

    app(KieModell::class)->erzeuge(['1x1' => 'Quadrat', '4x5' => 'Hochformat', '9x16' => 'Stories']);

    $urls = array_values(Http::recorded()->map(fn (array $paar): string => $paar[0]->url())->all());

    $auftraege = array_keys(array_filter($urls, fn (string $url): bool => str_contains($url, 'createTask')));
    $abfragen = array_keys(array_filter($urls, fn (string $url): bool => str_contains($url, 'recordInfo')));

    expect($auftraege)->toHaveCount(3)
        ->and($abfragen)->not->toBeEmpty()
        // Die Positionen stehen aufsteigend: der letzte Auftrag vor der
        // ersten Abfrage.
        ->and($auftraege[2] ?? PHP_INT_MAX)->toBeLessThan($abfragen[0] ?? -1);
});

it('behaelt die fertigen Formate, wenn eines scheitert', function (): void {
    kieMitMehrerenAuftraegen(['task_4:5' => 'fail']);

    $satz = app(KieModell::class)->erzeuge(['1x1' => 'Quadrat', '4x5' => 'Hochformat', '9x16' => 'Stories']);

    expect(array_keys($satz->bilder))->toBe(['1x1', '9x16'])
        ->and(array_keys($satz->fehler))->toBe(['4x5'])
        ->and($satz->fehler['4x5'])->toContain('blocked by policy');
});
