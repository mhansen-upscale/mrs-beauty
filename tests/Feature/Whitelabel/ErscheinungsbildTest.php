<?php

declare(strict_types=1);

use App\Datenschutz\Anhangspeicher;
use App\Datenschutz\Scanergebnis;
use App\Enums\AttachmentContext;
use App\Enums\Role;
use App\Models\Branding;
use App\Models\Organization;
use App\Models\User;
use App\Support\Farbe;
use App\Support\Markenstil;
use App\Whitelabel\Farbpruefung;
use Illuminate\Http\UploadedFile;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| WP-07 -- Whitelabel
|--------------------------------------------------------------------------
|
| Die Abnahmekriterien aus specs/WP-07-whitelabel.md. Die Regeln stehen in
| docs/design/farben.md, Abschnitt "Validierung bei der Eingabe".
|
*/

function markenverwaltung(Organization $organisation): User
{
    return User::factory()->fuer($organisation, Role::Owner)->create();
}

/*
|--------------------------------------------------------------------------
| Farbe
|--------------------------------------------------------------------------
*/

it('uebernimmt eine dunkle Farbe unveraendert', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $befund = app(Farbpruefung::class)->pruefe('#1F5D5B');

    expect($befund->angenommen)->toBeTrue()
        ->and($befund->farbe)->toBe('#1F5D5B')
        ->and($befund->hinweise)->toBeEmpty();
});

it('dunkelt eine zu helle Farbe ab und sagt es', function (): void {
    // **Regel 1 aus farben.md: Hinweis, nicht Ablehnung.**
    alsMandant(organisation('Demo-Praxis'));

    $befund = app(Farbpruefung::class)->pruefe('#C9A227');

    expect($befund->angenommen)->toBeTrue()
        ->and($befund->hinweise)->not->toBeEmpty()
        // Mit beiden Werten: die Praxis soll sehen, woran es liegt.
        ->and($befund->hinweise[0])->toContain('#C9A227')
        ->and($befund->hinweise[0])->toContain(':1');
});

it('warnt bei einem Farbton nahe den Statusfarben und uebernimmt trotzdem', function (): void {
    // **Regel 2 aus farben.md.**
    alsMandant(organisation('Demo-Praxis'));

    $rot = app(Farbpruefung::class)->pruefe('#B3261E');

    expect($rot->angenommen)->toBeTrue()
        ->and(implode(' ', $rot->hinweise))->toContain('Rot');

    $gruen = app(Farbpruefung::class)->pruefe('#1B5E20');

    expect($gruen->angenommen)->toBeTrue()
        ->and(implode(' ', $gruen->hinweise))->toContain('Grün');
});

it('lehnt Reinweiss ab', function (): void {
    // **Regel 3: nicht stillschweigend korrigieren.**
    alsMandant(organisation('Demo-Praxis'));

    $befund = app(Farbpruefung::class)->pruefe('#FFFFFF');

    expect($befund->angenommen)->toBeFalse()
        ->and($befund->ablehnung)->toContain('zu hell');
});

it('lehnt Neongelb ab', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    expect(app(Farbpruefung::class)->pruefe('#FFFF00')->angenommen)->toBeFalse();
});

it('lehnt ab, was kein Farbwert ist', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $befund = app(Farbpruefung::class)->pruefe('petrol bitte');

    expect($befund->angenommen)->toBeFalse()
        ->and($befund->ablehnung)->toContain('kein Farbwert');
});

it('laesst die Semantikfarben unberuehrt -- auch bei roter Marke', function (): void {
    // Der Erzeuger gibt genau drei Variablen aus und kann gar nichts anderes
    // ausgeben. Waere die Markenfarbe gruen, wuerde ein gruenes "bestanden"
    // in der HWG-Ampel mehrdeutig.
    $stil = Markenstil::fuer('#B3261E');

    expect(array_keys($stil))->toBe(Markenstil::ERLAUBT)
        ->and($stil)->not->toHaveKey('--destructive')
        ->and($stil)->not->toHaveKey('--success')
        ->and($stil)->not->toHaveKey('--warning');
});

it('laesst den Arbeitsbereich in der Produktfarbe', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    Branding::query()->create(['primary_color' => '#B3261E']);

    // Die Markenfarbe geht ausschliesslich an die Buchungsseite. Der
    // Admin-Bereich bekommt sie nie zu sehen -- geprueft an der Antwort, die
    // er ausliefert.
    $antwort = actingAs(markenverwaltung($organisation))->get(route('dashboard'));

    $antwort->assertOk();

    expect($antwort->getContent())->not->toContain('#B3261E');
});

/*
|--------------------------------------------------------------------------
| Speichern
|--------------------------------------------------------------------------
*/

it('speichert Farbe und Rechtslinks', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    actingAs(markenverwaltung($organisation))->put(route('erscheinungsbild.update'), [
        'primaryColor' => '#1F5D5B',
        'imprintUrl' => 'https://praxis.example/impressum',
        'privacyUrl' => 'https://praxis.example/datenschutz',
    ])->assertRedirect();

    $bild = Branding::query()->firstOrFail();

    expect($bild->primary_color)->toBe('#1F5D5B')
        ->and($bild->rechtlichVollstaendig())->toBeTrue();
});

it('nimmt nur Adressen mit https an', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    // Ein Impressum ueber http ist eines, dessen Aufruf sich mitlesen laesst.
    actingAs(markenverwaltung($organisation))
        ->put(route('erscheinungsbild.update'), ['imprintUrl' => 'http://praxis.example/impressum'])
        ->assertSessionHasErrors('imprintUrl');
});

it('weist eine abgelehnte Farbe am Feld zurueck', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    actingAs(markenverwaltung($organisation))
        ->put(route('erscheinungsbild.update'), ['primaryColor' => '#FFFFFF'])
        ->assertSessionHasErrors('primaryColor');

    expect(Branding::query()->count())->toBe(0);
});

it('laesst niemanden ohne whitelabel.manage an das Erscheinungsbild', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    actingAs(User::factory()->fuer($organisation, Role::Reception)->create())
        ->get(route('erscheinungsbild.edit'))
        ->assertForbidden();
});

it('zeigt keiner Praxis das Erscheinungsbild einer anderen', function (): void {
    alsMandant(organisation('Praxis A'));
    Branding::query()->create(['primary_color' => '#1F5D5B']);

    alsMandant(organisation('Praxis B'));

    expect(Branding::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Logo und Buchungsseite
|--------------------------------------------------------------------------
*/

it('zeigt ohne Logo den Namen der Praxis', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertInertia(fn ($seite) => $seite->where('logoUrl', null));
});

it('zeigt ein hochgeladenes Logo sofort', function (): void {
    // **Keine Virenpruefung als Schranke.** Das Logo laedt eine
    // Praxisinhaberin von ihrer eigenen Marke hoch; es ist kein ungefragt
    // zugesandtes Foto aus einem Chat. Ohne angebundenen Scanner steht
    // ueberall `unscanned` -- die strenge Regel haette bedeutet, dass ein
    // Logo niemals erscheint.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $bild = Branding::query()->create([]);

    app(Anhangspeicher::class)->lege(
        traeger: $bild,
        inhalt: (string) file_get_contents(base_path('public/favicon.ico')),
        dateiname: 'logo.png',
        kontext: AttachmentContext::BrandReference,
        wer: markenverwaltung($organisation),
    );

    $anhang = $bild->attachments()->firstOrFail();
    $anhang->mime = 'image/png';
    $anhang->save();

    expect($bild->refresh()->logo())->not->toBeNull();

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertInertia(fn ($seite) => $seite->where('logoUrl', route('buchung.logo', ['praxis' => $organisation->slug])));

    get(route('buchung.logo', ['praxis' => $organisation->slug]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        // Ohne das raet der Browser den Typ selbst.
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('liefert ein beanstandetes Logo nicht aus', function (): void {
    // Ein **Befund** blockiert, die fehlende Pruefung nicht.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $bild = Branding::query()->create([]);

    app(Anhangspeicher::class)->lege(
        traeger: $bild,
        inhalt: 'bilddaten',
        dateiname: 'logo.png',
        kontext: AttachmentContext::BrandReference,
        wer: markenverwaltung($organisation),
    );

    $anhang = $bild->attachments()->firstOrFail();
    $anhang->scan_result = Scanergebnis::Infected->value;
    $anhang->save();

    expect($bild->refresh()->logo())->toBeNull();

    ohneMandant();

    get(route('buchung.logo', ['praxis' => $organisation->slug]))->assertNotFound();
});

it('liefert nur erlaubte Bildarten aus', function (): void {
    // **Der Ausgang entscheidet, nicht die Eingabe von damals.** Steht an der
    // Datei etwas anderes, geht sie nicht hinaus -- auch wenn sie es einmal
    // durch das Formular geschafft hat.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $bild = Branding::query()->create([]);

    app(Anhangspeicher::class)->lege(
        traeger: $bild,
        inhalt: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        dateiname: 'logo.svg',
        kontext: AttachmentContext::BrandReference,
        wer: markenverwaltung($organisation),
    );

    $anhang = $bild->attachments()->firstOrFail();
    $anhang->mime = 'image/svg+xml';
    $anhang->save();

    ohneMandant();

    get(route('buchung.logo', ['praxis' => $organisation->slug]))->assertNotFound();
});

it('nimmt kein SVG als Logo an', function (): void {
    // Eine SVG-Datei kann ein Skript enthalten, und ausgeliefert von unserer
    // eigenen Adresse waere das ein Skript auf der oeffentlichen
    // Buchungsseite. Ein Virenscanner findet so etwas nicht.
    $organisation = alsMandant(organisation('Demo-Praxis'));

    actingAs(markenverwaltung($organisation))
        ->post(route('erscheinungsbild.logo'), [
            'datei' => UploadedFile::fake()->createWithContent(
                'logo.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            ),
        ])
        ->assertSessionHasErrors('datei');
});

it('nimmt kein Logo an, das keine Bilddatei ist', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    actingAs(markenverwaltung($organisation))
        ->post(route('erscheinungsbild.logo'), ['datei' => UploadedFile::fake()->create('liste.csv', 10, 'text/csv')])
        ->assertSessionHasErrors('datei');
});

it('stellt Impressum und Datenschutz auf die Buchungsseite', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    Branding::query()->create([
        'imprint_url' => 'https://praxis.example/impressum',
        'privacy_url' => 'https://praxis.example/datenschutz',
    ]);

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertInertia(fn ($seite) => $seite
            ->where('imprintUrl', 'https://praxis.example/impressum')
            ->where('privacyUrl', 'https://praxis.example/datenschutz')
        );
});

it('laesst die Buchungsseite ohne Impressum erreichbar und weist im Produkt darauf hin', function (): void {
    // Kein hartes Sperren: eine Buchungsseite, die wegen eines fehlenden
    // Links nicht mehr erreichbar ist, nimmt der Praxis Termine weg, statt
    // ihr zu helfen.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = markenverwaltung($organisation);

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))->assertOk();

    alsMandant($organisation);

    actingAs($inhaberin)
        ->get(route('erscheinungsbild.edit'))
        ->assertInertia(fn ($seite) => $seite->where('branding.rechtlichVollstaendig', false));
});

it('kennt keine feste Farbe im Oberflaechencode', function (): void {
    // Der Test aus WP-07, den docs/design/farben.md ankuendigt -- er stand
    // schon seit WP-02 und gilt weiter. Hier nur die Gegenprobe, dass die
    // Markenfarbe ihn nicht aushebelt: sie kommt als CSS-Variable, nicht als
    // Klasse.
    $quelle = (string) file_get_contents(resource_path('js/layouts/buchung/BuchungLayout.vue'));

    expect($quelle)->toContain(':style="stil"')
        ->and($quelle)->not->toMatch('/bg-\[#[0-9a-f]{3,8}\]/i');

    expect(Farbe::ausHex('#1F5D5B')->alsHslToken())->toBe('178 50% 24%');
});
