<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-38, Abnahmekriterien 7 bis 9 -- Impressum und Datenschutzerklaerung
|--------------------------------------------------------------------------
|
| Die Angaben stehen in der Konfiguration, nicht in der Vorlage: dort sind sie
| versioniert und an einer Stelle. Die Seite zeigt, was dort steht.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

it('zeigt das Impressum ohne Anmeldung mit den Angaben des Anbieters aus der Konfiguration', function (): void {
    config(['mrs.oeffentlich.anbieter.firma' => 'Beispiel GmbH', 'mrs.oeffentlich.anbieter.registernummer' => 'HRB 4711']);

    get('/impressum')
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('oeffentlich/Impressum')
            ->where('anbieter.firma', 'Beispiel GmbH')
            ->where('anbieter.registernummer', 'HRB 4711')
            ->where('anbieter.ust_id', config('mrs.oeffentlich.anbieter.ust_id'))
        );
});

it('fuehrt im Impressum alle Pflichtangaben', function (): void {
    // § 5 DDG: Name und Anschrift, Vertretung, Kontakt, Register und
    // Umsatzsteuer-ID. Ein leerer Wert waere ein Impressum mit Luecke.
    foreach (['firma', 'strasse', 'plz', 'ort', 'vertreten_durch', 'registergericht', 'registernummer', 'ust_id', 'email', 'telefon'] as $feld) {
        $wert = config("mrs.oeffentlich.anbieter.{$feld}");

        expect($wert)->toBeString()
            ->and(trim((string) $wert))->not->toBe('', "Im Impressum fehlt {$feld}.");
    }
});

it('zeigt die Datenschutzerklaerung ohne Anmeldung mit der Frist der Demo-Anfragen', function (): void {
    config(['mrs.oeffentlich.demoanfragen.aufbewahrung_monate' => 9]);

    get('/datenschutzerklaerung')
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('oeffentlich/Datenschutzerklaerung')
            ->where('aufbewahrungMonate', 9)
            ->where('anbieter.firma', config('mrs.oeffentlich.anbieter.firma'))
        );
});

it('nennt in der Datenschutzerklaerung jedes Cookie und jeden Drittanbieter der Startseite', function (): void {
    $cookies = collect(get('/')->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->sort()->values()->all();

    expect($cookies)->not->toBeEmpty();

    $erlaubt = array_keys((array) config('mrs.oeffentlich.drittanbieter'));
    sort($erlaubt);

    get('/datenschutzerklaerung')->assertInertia(fn ($seite) => $seite
        ->where('cookies', fn (Collection $genannt): bool => $genannt->pluck('name')->sort()->values()->all() === $cookies)
        ->where('drittanbieter', fn (Collection $genannt): bool => $genannt->pluck('host')->sort()->values()->all() === $erlaubt)
    );
});

it('laesst die Datenschutzseite der Praxis unter /datenschutz hinter der Anmeldung', function (): void {
    // Zwei Seiten, zwei Adressen: die Erklaerung des Betreibers ist
    // oeffentlich, die Fristen einer Praxis sind es nicht (WP-18).
    expect(route('privacy.index', absolute: false))->toBe('/datenschutz')
        ->and(route('datenschutzerklaerung', absolute: false))->toBe('/datenschutzerklaerung');

    get('/datenschutz')->assertRedirect(route('login'));
});
