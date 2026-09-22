<?php

declare(strict_types=1);

use App\Anzeigen\BildNichtErzeugt;
use App\Anzeigen\KieAi\KieModell;
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
*/

beforeEach(function (): void {
    config()->set('services.kie.key', 'geheim');
    config()->set('services.kie.url', 'https://kie.test/api/v1/jobs');
    config()->set('services.kie.model', 'google/nano-banana');
    config()->set('services.kie.poll_ms', 0);
});

it('schickt den Auftrag in der dokumentierten Form', function (): void {
    Http::fake([
        'kie.test/*/createTask' => Http::response(['code' => 200, 'data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['code' => 200, 'data' => [
            'state' => 'success',
            'resultJson' => '{"resultUrls":["https://bilder.test/eins.png"]}',
        ]]),
        'bilder.test/*' => Http::response('bilddaten', 200, ['Content-Type' => 'image/png']),
    ]);

    $bild = app(KieModell::class)->erzeuge('Ein ruhiger Empfangsbereich.');

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

    expect(app(KieModell::class)->erzeuge('Ein Wartebereich.')->mime)->toBe('image/webp');
});

it('nennt in der Meldung, was zurueckkam', function (): void {
    // **Der Fund, der diese Runde ausgeloest hat.** Eine Meldung, die
    // verschweigt, was zurueckkam, laesst nur raten.
    Http::fake([
        'kie.test/*/createTask' => Http::response(['code' => 402, 'msg' => 'Insufficient credits'], 402),
    ]);

    expect(fn () => app(KieModell::class)->erzeuge('Egal'))
        ->toThrow(BildNichtErzeugt::class, 'Insufficient credits');
});

it('nennt auch bei fehlender Auftragskennung die Antwort', function (): void {
    Http::fake([
        'kie.test/*/createTask' => Http::response(['code' => 200, 'msg' => 'success', 'data' => null]),
    ]);

    try {
        app(KieModell::class)->erzeuge('Egal');
        $this->fail('Es haette werfen muessen.');
    } catch (BildNichtErzeugt $fehler) {
        expect($fehler->getMessage())->toContain('keine Auftragskennung')
            ->and($fehler->getMessage())->toContain('HTTP 200')
            ->and($fehler->getMessage())->toContain('success');
    }
});

it('gibt einen fehlgeschlagenen Auftrag nicht als Bild aus', function (): void {
    Http::fake([
        'kie.test/*/createTask' => Http::response(['data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['data' => ['state' => 'fail', 'failMsg' => 'blocked by policy']]),
    ]);

    expect(fn () => app(KieModell::class)->erzeuge('Egal'))
        ->toThrow(BildNichtErzeugt::class, 'blocked by policy');
});

it('bricht ab, statt endlos zu fragen', function (): void {
    // Ein Auftrag, der nie fertig wird, haelt sonst die Warteschlange an.
    config()->set('services.kie.max_polls', 3);

    Http::fake([
        'kie.test/*/createTask' => Http::response(['data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['data' => ['state' => 'generating']]),
    ]);

    expect(fn () => app(KieModell::class)->erzeuge('Egal'))
        ->toThrow(BildNichtErzeugt::class, 'nicht rechtzeitig');
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

    expect(fn () => app(KieModell::class)->erzeuge('Egal'))
        ->toThrow(BildNichtErzeugt::class, 'image/svg+xml');
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
    config()->set('services.kie.input', ['aspect_ratio' => '1:1', 'resolution' => '2K']);

    Http::fake([
        'kie.test/*/createTask' => Http::response(['code' => 200, 'data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['code' => 200, 'data' => [
            'state' => 'success',
            'resultJson' => '{"resultUrls":["https://bilder.test/eins.png"]}',
        ]]),
        'bilder.test/*' => Http::response('bilddaten', 200, ['Content-Type' => 'image/png']),
    ]);

    app(KieModell::class)->erzeuge('Ein ruhiger Empfangsbereich.');

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
    config()->set('services.kie.input', ['aspect_ratio' => '1:1']);

    Http::fake([
        'kie.test/*/createTask' => Http::response(['code' => 200, 'data' => ['taskId' => 'task_1']]),
        'kie.test/*/recordInfo*' => Http::response(['code' => 200, 'data' => [
            'state' => 'success',
            'resultJson' => '{"resultUrls":["https://bilder.test/eins.png"]}',
        ]]),
        'bilder.test/*' => Http::response('bilddaten', 200, ['Content-Type' => 'image/png']),
    ]);

    app(KieModell::class)->erzeuge('Ein ruhiger Empfangsbereich.');

    Http::assertSent(function ($anfrage): bool {
        if (! str_contains($anfrage->url(), 'createTask')) {
            return false;
        }

        return ! array_key_exists('resolution', (array) ($anfrage->data()['input'] ?? []));
    });
});
