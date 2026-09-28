<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;
use function Pest\Laravel\withoutVite;

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| WP-38, Abnahmekriterien 10 und 11 -- kein Tracking
|--------------------------------------------------------------------------
|
| Die oeffentlichen Seiten setzen nur, was die Sitzung braucht, und laden nur
| eigene Dateien und die Drittanbieter aus `mrs.oeffentlich.drittanbieter` --
| dieselbe Liste, die die Datenschutzerklaerung nennt. Ein Pixel, das jemand
| spaeter einbaut, scheitert hier, nicht erst an einer Abmahnung.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Die Hosts, von denen ein HTML etwas nachlaedt -- ueber `src` oder ein
 * `<link href>` --, ohne die eigene Adresse und die erlaubten.
 *
 * @return list<string>
 */
function fremdeQuellen(string $html): array
{
    $erlaubt = [
        parse_url((string) config('app.url'), PHP_URL_HOST),
        ...array_keys((array) config('mrs.oeffentlich.drittanbieter')),
    ];

    preg_match_all('/\bsrc=["\'](?:https?:)?\/\/([^\/"\']+)/i', $html, $quellen);
    preg_match_all('/<link\b[^>]*\bhref=["\'](?:https?:)?\/\/([^\/"\']+)/i', $html, $verweise);

    return array_values(array_unique(array_filter(
        [...$quellen[1], ...$verweise[1]],
        fn (string $host): bool => ! in_array($host, $erlaubt, true),
    )));
}

/** @return list<string> Die Vue-Dateien der oeffentlichen Seiten. */
function oeffentlicheVorlagen(): array
{
    $dateien = [];

    foreach (Finder::create()->files()->name('*.vue')->in([
        resource_path('js/pages/oeffentlich'),
        resource_path('js/layouts/oeffentlich'),
        resource_path('js/components/oeffentlich'),
    ]) as $datei) {
        $dateien[] = (string) $datei->getRealPath();
    }

    return $dateien;
}

it('setzt auf der Startseite nur Sitzungs- und XSRF-Cookie', function (): void {
    $namen = collect(get('/')->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->sort()->values()->all();

    expect($namen)->toBe(collect([(string) config('session.cookie'), 'XSRF-TOKEN'])->sort()->values()->all());
});

it('laedt auf den oeffentlichen Seiten nur eigene Quellen und die genannten Drittanbieter', function (string $pfad): void {
    withoutVite();

    expect(fremdeQuellen((string) get($pfad)->assertOk()->getContent()))->toBe([]);
})->with(['/', '/impressum', '/datenschutzerklaerung']);

it('laedt auch aus den Vorlagen der oeffentlichen Seiten nichts Fremdes', function (): void {
    $vorlagen = oeffentlicheVorlagen();

    expect($vorlagen)->not->toBeEmpty();

    foreach ($vorlagen as $pfad) {
        $inhalt = (string) file_get_contents($pfad);

        expect(fremdeQuellen($inhalt))->toBe([], basename($pfad).' laedt von einem fremden Rechner.')
            ->and($inhalt)->not->toMatch('/@import\s+url\(\s*["\']?https?:/i')
            ->and($inhalt)->not->toContain('<iframe');
    }
});

it('erkennt eine fremde Quelle, wenn es eine gibt', function (): void {
    // Die Gegenprobe: das Muster findet Pixel und Skripte, laesst die eigene
    // Adresse und die erlaubte Schrift aber durch.
    $eigene = rtrim((string) config('app.url'), '/');

    expect(fremdeQuellen('<script src="https://connect.facebook.net/de_DE/fbevents.js"></script>'))->toBe(['connect.facebook.net'])
        ->and(fremdeQuellen('<img src="//www.google-analytics.com/collect?v=1">'))->toBe(['www.google-analytics.com'])
        ->and(fremdeQuellen('<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Inter">'))->toBe(['fonts.googleapis.com'])
        ->and(fremdeQuellen('<link href="https://fonts.bunny.net/css?family=instrument-sans" rel="stylesheet">'))->toBe([])
        ->and(fremdeQuellen("<script src=\"{$eigene}/build/app.js\"></script>"))->toBe([])
        ->and(fremdeQuellen('<script src="/build/app.js"></script>'))->toBe([]);
});
