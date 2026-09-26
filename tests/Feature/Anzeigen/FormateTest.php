<?php

declare(strict_types=1);

use App\Abrechnung\Nutzungsuebersicht;
use App\Anzeigen\Bildmodell;
use App\Anzeigen\Entwurf;
use App\Anzeigen\Vorschlagslauf;
use App\Enums\Ampel;
use App\Enums\Bildformat;
use App\Enums\Role;
use App\Jobs\AnzeigenbildErzeugen;
use App\Models\AdSuggestion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Anzeigen\Bildattrappe;
use Tests\Feature\Anzeigen\Mitschnitt;

/*
|--------------------------------------------------------------------------
| WP-31b -- Anzeigenformate
|--------------------------------------------------------------------------
|
| Die Abnahmekriterien aus specs/WP-31b-anzeigenformate.md, soweit sie das
| Erzeugen, das Kontingent und die Seite betreffen. Das Schalten steht in
| AnzeigenschaltungTest, die Anbindung an kie.ai in KieModellTest.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-27 10:00:00', 'UTC'));
});

/**
 * Eine selbst geschriebene Anzeige -- ohne Sprachmodell und ohne Brand
 * Guide, weil beides hier nichts zur Sache tut.
 *
 * @return array{AdSuggestion, User}
 */
function entwurfFuerFormate(): array
{
    $organisation = alsMandant(organisation('Demo-Praxis'));

    $vorschlag = app(Vorschlagslauf::class)->legeVonHand(new Entwurf(
        ueberschrift: 'In Ruhe beraten lassen',
        text: 'Wir nehmen uns Zeit für Ihre Fragen.',
        handlungsaufruf: 'Termin anfragen',
    ));

    return [$vorschlag, User::factory()->fuer($organisation, Role::Owner)->create()];
}

/** @return list<string> */
function formatwerte(): array
{
    return array_map(fn (Bildformat $f): string => $f->value, Bildformat::cases());
}

/*
|--------------------------------------------------------------------------
| Erzeugen
|--------------------------------------------------------------------------
*/

it('erzeugt je Format eine Grafik', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::liefert());

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);

    $frisch = AdSuggestion::query()->whereKey($vorschlag->getKey())->firstOrFail();

    // Quadrat fuer die rechte Spalte, Hochformat fuer den Feed, 9:16 fuer
    // Stories -- ein Auftrag je Format, nicht einer fuer alle.
    expect(array_keys(Mitschnitt::$bildauftraege))->toBe(['1x1', '4x5', '9x16'])
        ->and(array_keys($frisch->bilder()))->toBe(['1x1', '4x5', '9x16'])
        ->and($frisch->fehlendeFormate())->toBe([])
        ->and($frisch->image_error)->toBeNull();
});

it('nennt jedem Format sein Seitenverhaeltnis', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::liefert());

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);

    expect(Mitschnitt::$bildauftraege['1x1'])->toContain('1:1')
        ->and(Mitschnitt::$bildauftraege['4x5'])->toContain('4:5')
        ->and(Mitschnitt::$bildauftraege['9x16'])->toContain('9:16')
        // Die erste Fassung schrieb "Quadratische Werbegrafik" in jeden
        // Auftrag. Ein Modell, dem man 9:16 schickt und "quadratisch" sagt,
        // hat die Wahl.
        ->and(Mitschnitt::$bildauftraege['9x16'])->not->toContain('Quadratisch');
});

/**
 * **Stories haben Raender, die nicht uns gehoeren.** Oben liegen Profilbild
 * und Name, unten Antwortfeld und Schaltflaechen. Schrift dort verschwindet
 * unter der Oberflaeche der App.
 */
it('haelt im Auftrag fuer 9:16 die Raender der Stories frei', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::liefert());

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);

    $zone = (array) config('mrs.ads.formate.9x16.schutzzone');

    expect(Mitschnitt::$bildauftraege['9x16'])
        ->toContain('oben '.$zone['oben'].' %')
        ->toContain('unten '.$zone['unten'].' %')
        ->toContain('seitlich je '.$zone['seiten'].' %')
        // Der Feed hat keine solchen Raender -- dort steht die Schrift
        // weiter in der unteren Bildhaelfte.
        ->and(Mitschnitt::$bildauftraege['4x5'])->not->toContain('unten '.$zone['unten'].' %');
});

it('nennt jedem Format die Ueberschrift woertlich und die Grenzen', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::liefert());

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);

    // **Drei Grafiken, dreimal dieselbe Pruefung.** Ein Format, dem die
    // Grenzen fehlen, ist eine Grafik, die sie nicht kennt.
    foreach (Mitschnitt::$bildauftraege as $auftrag) {
        expect($auftrag)->toContain('"In Ruhe beraten lassen"')
            ->toContain('ohne Änderung')
            ->toContain('Keine Vorher-Nachher-Darstellung')
            ->toContain('keine Behandlungssituation am Körper');
    }
});

/**
 * **Ein Fehlschlag nimmt den anderen nichts.** Jedes Format ist bezahlt,
 * sobald es beim Anbieter liegt.
 */
it('behaelt die gelungenen Formate, wenn eines scheitert', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::ohne('9x16', 'kie.ai konnte kein Bild erzeugen: blocked by policy'));

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);

    $frisch = AdSuggestion::query()->whereKey($vorschlag->getKey())->firstOrFail();

    expect(array_keys($frisch->bilder()))->toBe(['1x1', '4x5'])
        ->and($frisch->fehlendeFormate())->toBe([Bildformat::Story])
        // Welches fehlt und warum -- im Produkt, nicht im Log (Regel 4).
        ->and((string) $frisch->image_error)->toContain('9:16')
        ->and((string) $frisch->image_error)->toContain('blocked by policy')
        // Was entstand, hat niemand gelesen: auch zwei Formate nehmen das
        // Gruen.
        ->and($frisch->ampel())->toBe(Ampel::Gelb)
        // Und es hat Geld gekostet.
        ->and(app(Nutzungsuebersicht::class)->bilder(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
        ))->toBe(1);
});

it('haelt den Grund fest, wenn kein Format entsteht', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::scheitert('kie.ai wurde nicht rechtzeitig fertig.'));

    app(AnzeigenbildErzeugen::class, [
        'organisation' => (string) $vorschlag->organization?->uuid,
        'vorschlag' => (string) $vorschlag->uuid,
        'benutzer' => (string) $inhaberin->uuid,
    ])->handle(app(TenantContext::class), app(Vorschlagslauf::class));

    $frisch = AdSuggestion::query()->whereKey($vorschlag->getKey())->firstOrFail();

    expect($frisch->image_error)->toBe('kie.ai wurde nicht rechtzeitig fertig.')
        ->and($frisch->bilder())->toBe([])
        ->and(app(Nutzungsuebersicht::class)->bilder(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
        ))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Kontingent (B13, angepasst am 27.09.2026)
|--------------------------------------------------------------------------
*/

/**
 * **Ein Formatsatz ist eine Grafik.** Die drei Formate sind keine Wahl der
 * Praxis, sondern Pflicht jeder Anzeige. Je Datei gezaehlt, wuerden aus 30
 * enthaltenen Grafiken stillschweigend 10 Anzeigen.
 */
it('zaehlt einen Formatsatz als eine Grafik', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::liefert());

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);

    expect($vorschlag->attachments()->count())->toBe(count(Bildformat::cases()))
        ->and(app(Nutzungsuebersicht::class)->bilder(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
        ))->toBe(1);
});

it('zaehlt einen zweiten Satz ein zweites Mal und behaelt den ersten', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::liefert());

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);
    $erster = AdSuggestion::query()->whereKey($vorschlag->getKey())->firstOrFail()->bilder();

    travelTo(CarbonImmutable::now()->addMinute());
    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);
    $zweiter = AdSuggestion::query()->whereKey($vorschlag->getKey())->firstOrFail()->bilder();

    expect($vorschlag->attachments()->count())->toBe(2 * count(Bildformat::cases()))
        ->and(app(Nutzungsuebersicht::class)->bilder(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
        ))->toBe(2);

    // Gezeigt wird je Format die neueste Fassung.
    foreach (formatwerte() as $format) {
        expect($zweiter[$format]->uuid)->not->toBe($erster[$format]->uuid);
    }
});

/*
|--------------------------------------------------------------------------
| Oberflaeche
|--------------------------------------------------------------------------
*/

it('zeigt jedes Format mit eigener Adresse', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::liefert());

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);

    actingAs($inhaberin)
        ->get(route('anzeigen.index'))
        ->assertInertia(fn ($seite) => $seite
            ->has('vorschlaege.0.bilder', count(Bildformat::cases()))
            ->where('vorschlaege.0.bilder.1.format', '4x5')
            ->where('vorschlaege.0.bilder.1.seitenverhaeltnis', '4:5')
            ->where('vorschlaege.0.bilder.2.url', fn (?string $url): bool => is_string($url) && str_contains($url, 'format=9x16')));

    // **Die Bildroute liefert jedes Format einzeln** -- wer freigibt, muss
    // die Schrift auf allen dreien lesen koennen.
    $antwort = actingAs($inhaberin)
        ->get(route('anzeigen.bild', ['vorschlag' => $vorschlag->uuid, 'format' => '9x16']))
        ->assertOk();

    expect($antwort->streamedContent())->toBe('bilddaten');

    actingAs($inhaberin)
        ->get(route('anzeigen.bild', ['vorschlag' => $vorschlag->uuid, 'format' => '16x9']))
        ->assertNotFound();
});

/**
 * **Auch eine zweite Grafik entsteht sichtbar.** Bis WP-31b galt "entsteht
 * gerade" nur, solange noch gar keine Grafik da war -- beim Nacherzeugen
 * stand die alte unveraendert da, und niemand sah, dass etwas passiert.
 */
it('zeigt eine zweite Grafik als entstehend, auch wenn schon eine da ist', function (): void {
    [$vorschlag, $inhaberin] = entwurfFuerFormate();
    app()->instance(Bildmodell::class, Bildattrappe::liefert());

    app(Vorschlagslauf::class)->erzeugeBild($vorschlag, $inhaberin);

    travelTo(CarbonImmutable::now()->addMinute());
    Queue::fake();

    actingAs($inhaberin)
        ->post(route('anzeigen.bild.anfordern', ['vorschlag' => $vorschlag->uuid]))
        ->assertSessionHas('erfolg');

    actingAs($inhaberin)
        ->get(route('anzeigen.index'))
        ->assertInertia(fn ($seite) => $seite
            ->where('vorschlaege.0.hatBild', true)
            ->where('vorschlaege.0.bildLaeuft', true)
            ->where('vorschlaege.0.bildFehler', null));
});
