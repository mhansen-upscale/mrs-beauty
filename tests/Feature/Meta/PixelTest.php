<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Treatment;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\withCookie;

/*
|--------------------------------------------------------------------------
| Das Pixel baut das Produkt ein, nicht der Kunde
|--------------------------------------------------------------------------
|
| Regel 2 in CLAUDE.md: Behandlungsname, treatment_id, Kategorie und
| Katalogbezeichnung verlassen das System niemals in Richtung Meta. Ein Pixel
| auf einer Buchungsseite fuer aesthetische Eingriffe ist genau die Stelle,
| an der das passieren wuerde -- die gewaehlte Behandlung steht auf der Seite.
|
| Deshalb nimmt die Praxis nur eine ID entgegen und keinen Skriptschnipsel:
| ein Feld, in das sich beliebiger Code einfuegen laesst, waere die Hintertuer,
| die dieses Produkt gerade schliesst. Und deshalb steht `autoConfig` aus --
| mit ihm schickt das Pixel Seitentitel und Beschriftungen von sich aus mit.
|
*/

it('reicht die hinterlegte Pixel-ID nach der Einwilligung durch', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $organisation->settings = ['tracking' => ['meta_pixel_id' => '123456789012345']];
    $organisation->save();

    ohneMandant();

    withCookie((string) config('mrs.attribution.consent_cookie'), 'ja')
        ->get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertInertia(fn ($seite) => $seite->where('pixelId', '123456789012345'));
});

it('laedt ohne Einwilligung kein Pixel', function (): void {
    // **Paragraf 25 TTDSG** (WP-32a). Bis dahin feuerte das Pixel hier
    // ungefragt -- eine Praxis, die fuer Werbung wirbt, darf nicht diejenige
    // sein, die deswegen abgemahnt wird.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $organisation->settings = ['tracking' => ['meta_pixel_id' => '123456789012345']];
    $organisation->save();

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertInertia(fn ($seite) => $seite->where('pixelId', null)->where('messung', null));

    // Und nach einer Ablehnung ebenfalls nicht -- die Seite bleibt benutzbar.
    withCookie((string) config('mrs.attribution.consent_cookie'), 'nein')
        ->get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->where('pixelId', null)->where('messung', 'nein'));
});

it('laedt ohne hinterlegte ID kein Pixel', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertInertia(fn ($seite) => $seite->where('pixelId', null));
});

it('nimmt nur eine Ziffernfolge entgegen, keinen Skriptschnipsel', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($benutzer);

    from(route('tracking.edit'))
        ->put(route('tracking.update'), ['meta_pixel_id' => '<script>fbq("track","Lead",{content_name:"Botox"})</script>'])
        ->assertSessionHasErrors('meta_pixel_id');

    expect(data_get($organisation->fresh()?->settings, 'tracking.meta_pixel_id'))->toBeNull();
});

it('speichert eine gueltige ID', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($benutzer)
        ->put(route('tracking.update'), ['meta_pixel_id' => '987654321098'])
        ->assertSessionHasNoErrors();

    expect(data_get($organisation->fresh()?->settings, 'tracking.meta_pixel_id'))->toBe('987654321098');
});

/* Regel 2 ------------------------------------------------------------------ */

it('sendet ausschliesslich PageView und Lead, ohne einen einzigen Parameter', function (): void {
    $quelle = (string) file_get_contents(resource_path('js/layouts/buchung/BuchungLayout.vue'));

    preg_match_all("/fbq\('track',([^)]*)\)/", $quelle, $treffer);

    expect($treffer[1])->not->toBeEmpty();

    foreach ($treffer[1] as $aufruf) {
        // **Das dritte Argument bleibt leer.** Dort landen bei Meta
        // content_name, content_category und content_ids -- die drei Felder,
        // die Regel 2 verbietet.
        //
        // Das vierte darf seit WP-32b die Ereigniskennung tragen: ohne sie
        // zaehlt Meta doppelt, weil die Conversions API dasselbe Ereignis
        // noch einmal meldet (attribution.md, Testfall 8). Genau diese eine
        // Form ist erlaubt, keine andere.
        expect(trim($aufruf))->toBeIn([
            "'PageView'",
            "'Lead'",
            "'Lead', {}, { eventID: props.leadEventId }",
        ]);
    }
});

it('schaltet autoConfig ab, bevor das Pixel initialisiert wird', function (): void {
    // autoConfig laesst Meta selbst entscheiden, was gesendet wird: Titel der
    // Seite, Beschriftungen angeklickter Schaltflaechen, Formularfelder. Auf
    // dieser Seite stehen Behandlungsnamen.
    $quelle = (string) file_get_contents(resource_path('js/layouts/buchung/BuchungLayout.vue'));

    $aus = mb_strpos($quelle, "fbq('set', 'autoConfig', false");
    $init = mb_strpos($quelle, "fbq('init'");

    expect($aus)->toBeInt();
    expect($init)->toBeInt();
    expect((int) $aus)->toBeLessThan((int) $init);
});

it('traegt keinen Katalognamen in den Pixelaufruf', function (): void {
    // Die Gegenprobe zum Kommentar: nicht die Absicht wird geprueft, sondern
    // der Text. Steht spaeter jemals ein Behandlungsname in dieser Datei,
    // faellt es hier auf.
    alsMandant(organisation('Demo-Praxis'));

    Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);

    $quelle = (string) file_get_contents(resource_path('js/layouts/buchung/BuchungLayout.vue'));
    $pixelteil = mb_substr($quelle, (int) mb_strpos($quelle, 'const pixel'));

    foreach (Treatment::aktiveNamen() as $name) {
        expect($pixelteil)->not->toContain($name);
    }
});
