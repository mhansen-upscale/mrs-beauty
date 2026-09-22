<?php

declare(strict_types=1);

use App\Support\Farbe;
use App\Support\Markenstil;

/*
|--------------------------------------------------------------------------
| WP-12, Abnahmekriterien 21 bis 25 -- Markenfarbe
|--------------------------------------------------------------------------
|
| docs/design/farben.md: "Die Semantikvariablen sind von der Ueberschreibung
| ausgenommen, das wird im Erzeuger der Branding-Stile erzwungen, nicht durch
| Konvention." Diese Datei ist der Nachweis.
|
*/

it('gibt ohne Markenfarbe nichts aus', function (?string $wert): void {
    // Leer heisst: die Produktfarbe aus app.css bleibt stehen.
    expect(Markenstil::fuer($wert))->toBe([]);
})->with([[null], [''], ['   '], ['kein-hex'], ['#12'], ['#gggggg']]);

it('ueberschreibt Primaerfarbe und Fokusrahmen', function (): void {
    $stil = Markenstil::fuer('#E91E8C');

    expect($stil)->toHaveKeys(['--primary', '--primary-foreground', '--ring'])
        ->and($stil['--primary-foreground'])->toBe('0 0% 100%');
});

it('ueberschreibt keine Semantikfarbe', function (): void {
    // Waere die Markenfarbe einer Praxis gruen, wuerde ein gruenes
    // "bestanden" in der HWG-Ampel mehrdeutig. Deshalb kann der Erzeuger gar
    // nichts anderes ausgeben als diese drei.
    foreach (['#15803D', '#B91C1C', '#B45309', '#1D4ED8', '#FFFF00'] as $farbe) {
        expect(array_keys(Markenstil::fuer($farbe)))->toBe(Markenstil::ERLAUBT);
    }
});

it('haelt Weiss auf der Markenfarbe lesbar', function (string $hex): void {
    $stil = Markenstil::fuer($hex);

    // **Geprueft wird der Token**, nicht die gerechnete Farbe: er rundet auf
    // ganze Grad und ganze Prozent, und genau er landet im Browser.
    $abgeleitet = Farbe::ausHslToken($stil['--primary']);

    expect($abgeleitet->kontrastZu(Farbe::weiss()))->toBeGreaterThanOrEqual(4.5);
})->with([
    '#1F5D5B',   // Petrol, bereits dunkel genug
    '#FFFF00',   // Neongelb -- der Grenzfall
    '#7CFC00',   // Neongruen
    '#E91E8C',   // Magenta
    '#C9A227',   // Gold, die haeufigste Markenfarbe der Branche
    '#FF6B6B',   // Blush
    '#FFFFFF',   // Reinweiss
    '#00FFFF',   // Cyan
]);

it('behaelt den Farbton', function (): void {
    // Ueber OKLCH abgeleitet: Farbton und Chroma bleiben, nur die Helligkeit
    // wandert. Eine Ableitung ueber HSL bleicht gesaettigte Toene aus.
    // Gelb liegt bei 60 Grad. Die Wahrnehmungskorrektur von OKLab verschiebt
    // den Ton leicht -- aus Gelb darf aber kein Blau werden.
    [$farbton] = explode(' ', Markenstil::fuer('#FFFF00')['--primary']);

    expect((int) $farbton)->toBeGreaterThan(40)->toBeLessThan(80);
});

it('dunkelt ab statt zu entsaettigen', function (): void {
    [, $saettigung, $helligkeit] = explode(' ', Markenstil::fuer('#FFFF00')['--primary']);

    expect((int) rtrim($saettigung, '%'))->toBeGreaterThan(50)
        ->and((int) rtrim($helligkeit, '%'))->toBeLessThan(45);
});
