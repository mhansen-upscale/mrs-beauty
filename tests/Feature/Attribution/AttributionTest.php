<?php

declare(strict_types=1);

use App\Attribution\Beruehrungen;
use App\Attribution\Zuordnung;
use App\Datenschutz\Aufbewahrung;
use App\Enums\AttributionModel;
use App\Enums\LeadSource;
use App\Enums\RetentionSubject;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AttributionTouch;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-32a -- Attribution: Erfassung und Zuordnung
|--------------------------------------------------------------------------
|
| Die Abnahmekriterien aus specs/WP-32a-attribution-erfassung.md, darunter
| die Testfaelle 1 bis 6 und 9 aus docs/fachlogik/attribution.md.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-17 09:00:00', 'UTC'));
});

/**
 * Eine Anfrage an die Buchungsseite, mit oder ohne Einwilligung.
 *
 * @param  array<string, string>  $anfrage
 * @param  array<string, string>  $cookies
 */
function seitenaufruf(array $anfrage = [], array $cookies = [], ?string $verweis = null): Request
{
    $request = Request::create('/buchen/demo-praxis', 'GET', $anfrage, $cookies);

    if ($verweis !== null) {
        $request->headers->set('referer', $verweis);
    }

    return $request;
}

/**
 * @param  array<string, string>  $anfrage
 */
function mitEinwilligung(array $anfrage = [], string $besucher = 'besucher-1', ?string $verweis = null): Request
{
    return seitenaufruf($anfrage, [
        (string) config('mrs.attribution.consent_cookie') => 'ja',
        (string) config('mrs.attribution.visitor_cookie_name') => $besucher,
    ], $verweis);
}

/*
|--------------------------------------------------------------------------
| Einwilligung
|--------------------------------------------------------------------------
*/

it('schreibt ohne Entscheidung keinen Touch', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $touch = app(Beruehrungen::class)->erfasse(seitenaufruf(['fbclid' => 'abc']));

    // Paragraf 25 TTDSG: die Einwilligung muss vorliegen, **bevor** das
    // Cookie gesetzt wird, nicht waehrend.
    expect($touch)->toBeNull()
        ->and(AttributionTouch::query()->count())->toBe(0);
});

it('schreibt nach einer Ablehnung ebenfalls keinen', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $touch = app(Beruehrungen::class)->erfasse(seitenaufruf([], [
        (string) config('mrs.attribution.consent_cookie') => 'nein',
    ]));

    expect($touch)->toBeNull();
});

it('schreibt mit Einwilligung einen Touch', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $touch = app(Beruehrungen::class)->erfasse(mitEinwilligung(['fbclid' => 'klick-1']));

    expect($touch)->not->toBeNull()
        ->and($touch?->visitor_id)->toBe('besucher-1')
        ->and($touch?->click_id)->toBe('klick-1');
});

/*
|--------------------------------------------------------------------------
| Erfassung
|--------------------------------------------------------------------------
*/

it('legt UTM-Angaben in ihre Spalten', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $touch = app(Beruehrungen::class)->erfasse(mitEinwilligung([
        'utm_source' => 'facebook',
        'utm_medium' => 'paid',
        'utm_campaign' => 'herbst',
        'utm_content' => 'motiv-a',
        'utm_term' => 'umkreis',
        'mrs_campaign' => 'camp-1',
        'mrs_adset' => 'adset-1',
        'mrs_ad' => 'ad-1',
    ]));

    expect($touch?->utm_source)->toBe('facebook')
        ->and($touch?->utm_campaign)->toBe('herbst')
        ->and($touch?->campaign_external_id)->toBe('camp-1')
        ->and($touch?->ad_external_id)->toBe('ad-1');
});

it('speichert den Abfrageteil der Adresse nicht', function (): void {
    // **Der Fund, der die Spaltennamen geaendert hat.** Sobald der Touch
    // rueckwirkend mit einem Kontakt verknuepft ist, stuende eine
    // Behandlungsbezeichnung aus der Adresse unverschluesselt daneben
    // (Regel 3).
    alsMandant(organisation('Demo-Praxis'));

    Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);

    $request = Request::create('/buchen/demo-praxis?behandlung=Botox&utm_source=facebook', 'GET', [
        'behandlung' => 'Botox',
        'utm_source' => 'facebook',
    ], [
        (string) config('mrs.attribution.consent_cookie') => 'ja',
        (string) config('mrs.attribution.visitor_cookie_name') => 'besucher-1',
    ]);

    $touch = app(Beruehrungen::class)->erfasse($request);

    expect($touch?->landing_path)->toBe('/buchen/demo-praxis');

    foreach (Treatment::aktiveNamen() as $behandlung) {
        foreach ((array) $touch?->getAttributes() as $spalte => $wert) {
            if (is_string($wert)) {
                expect($wert)->not->toContain($behandlung, "Spalte {$spalte}");
            }
        }
    }
});

it('behaelt vom Verweis nur den Host', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $touch = app(Beruehrungen::class)->erfasse(
        mitEinwilligung(verweis: 'https://www.instagram.com/p/abc123/?hl=de')
    );

    // Woher jemand kam, ist eine Quelle; welche Seite genau, ist eine
    // Aussage ueber ihn.
    expect($touch?->referrer_host)->toBe('www.instagram.com');
});

it('haelt den eigenen Host nicht fuer eine Quelle', function (): void {
    // Gefunden im Durchlauf gegen die Entwicklungsumgebung: der zweite
    // Aufruf der Buchungsseite trug den eigenen Host als Verweis -- und
    // haette als Touch **mit** Quelle gegolten. Last-Non-Direct haette dann
    // nie einen Direktaufruf uebersprungen, weil es keinen mehr gaebe.
    alsMandant(organisation('Demo-Praxis'));

    $touch = app(Beruehrungen::class)->erfasse(
        mitEinwilligung(verweis: 'http://localhost/buchen/demo-praxis')
    );

    expect($touch?->referrer_host)->toBeNull()
        ->and($touch?->hatQuelle())->toBeFalse();
});

it('schreibt zwei Aufrufe desselben Besuchers als zwei Touches', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    app(Beruehrungen::class)->erfasse(mitEinwilligung(['utm_source' => 'facebook']));
    travelTo(CarbonImmutable::now()->addDay());
    app(Beruehrungen::class)->erfasse(mitEinwilligung());

    expect(AttributionTouch::query()->count())->toBe(2)
        ->and(AttributionTouch::query()->fuerBesucher('besucher-1')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Zuordnung
|--------------------------------------------------------------------------
*/

/**
 * Legt eine Kette von Beruehrungen fuer einen Kontakt.
 *
 * @param  list<array{tage: int, quelle: ?string, kampagne: ?string}>  $kette
 */
function beruehrungskette(Contact $kontakt, array $kette): void
{
    foreach ($kette as $glied) {
        AttributionTouch::query()->create([
            'visitor_id' => 'besucher-1',
            'utm_source' => $glied['quelle'],
            'campaign_external_id' => $glied['kampagne'],
            'occurred_at' => CarbonImmutable::now()->subDays($glied['tage']),
            'contact_id' => $kontakt->getKey(),
        ]);
    }
}

it('ordnet bei First und Last verschieden zu', function (): void {
    // Testfall 3.
    alsMandant(organisation('Demo-Praxis'));
    $kontakt = Contact::factory()->create();

    beruehrungskette($kontakt, [
        ['tage' => 10, 'quelle' => 'facebook', 'kampagne' => 'camp-erste'],
        ['tage' => 2, 'quelle' => 'google', 'kampagne' => 'camp-letzte'],
    ]);

    $zuordnung = app(Zuordnung::class);
    $jetzt = CarbonImmutable::now();

    expect($zuordnung->touch($kontakt, $jetzt, AttributionModel::FirstTouch)?->campaign_external_id)->toBe('camp-erste')
        ->and($zuordnung->touch($kontakt, $jetzt, AttributionModel::LastTouch)?->campaign_external_id)->toBe('camp-letzte');
});

it('ueberspringt einen Direktaufruf bei Last-Non-Direct', function (): void {
    // Testfall 4. Ein Direktaufruf ist keine Quelle, sondern das Fehlen
    // einer.
    alsMandant(organisation('Demo-Praxis'));
    $kontakt = Contact::factory()->create();

    beruehrungskette($kontakt, [
        ['tage' => 5, 'quelle' => 'facebook', 'kampagne' => 'camp-1'],
        ['tage' => 1, 'quelle' => null, 'kampagne' => null],
    ]);

    $zuordnung = app(Zuordnung::class);
    $jetzt = CarbonImmutable::now();

    expect($zuordnung->touch($kontakt, $jetzt, AttributionModel::LastTouch)?->campaign_external_id)->toBeNull()
        ->and($zuordnung->touch($kontakt, $jetzt, AttributionModel::LastNonDirect)?->campaign_external_id)->toBe('camp-1');
});

it('ordnet ausserhalb des Rueckblickfensters nicht zu', function (): void {
    // Testfall 5.
    alsMandant(organisation('Demo-Praxis'));
    $kontakt = Contact::factory()->create();

    beruehrungskette($kontakt, [
        ['tage' => 40, 'quelle' => 'facebook', 'kampagne' => 'camp-alt'],
    ]);

    expect(app(Zuordnung::class)->touch($kontakt, CarbonImmutable::now()))->toBeNull();

    // Mit einem laengeren Fenster kommt sie wieder.
    config()->set('mrs.attribution.lookback_days', 60);

    expect(app(Zuordnung::class)->touch($kontakt, CarbonImmutable::now())?->campaign_external_id)
        ->toBe('camp-alt');
});

it('verteilt bei Linear gleichmaessig', function (): void {
    alsMandant(organisation('Demo-Praxis'));
    $kontakt = Contact::factory()->create();

    beruehrungskette($kontakt, [
        ['tage' => 5, 'quelle' => 'facebook', 'kampagne' => 'camp-1'],
        ['tage' => 3, 'quelle' => 'google', 'kampagne' => 'camp-2'],
        ['tage' => 1, 'quelle' => 'instagram', 'kampagne' => 'camp-3'],
    ]);

    $anteile = app(Zuordnung::class)->anteile($kontakt, CarbonImmutable::now(), AttributionModel::Linear);

    expect($anteile)->toHaveCount(3)
        ->and(round(array_sum($anteile), 6))->toBe(1.0)
        ->and(round(array_values($anteile)[0], 6))->toBe(round(1 / 3, 6));
});

it('nennt einen Lead ohne Besucherzuordnung Quelle unbekannt', function (): void {
    // Testfall 9: null heisst "wir wissen es nicht", nicht "es kam von
    // nirgendwo".
    alsMandant(organisation('Demo-Praxis'));
    $kontakt = Contact::factory()->create();

    expect(app(Zuordnung::class)->touch($kontakt, CarbonImmutable::now()))->toBeNull()
        ->and(app(Zuordnung::class)->standFuer($kontakt, CarbonImmutable::now()))->toBeNull();
});

it('friert den Stand als Kopie ein, nicht als Verweis', function (): void {
    // Testfall 2: eine Umbenennung der Kampagne bei Meta veraendert den
    // Snapshot eines bestehenden Termins nicht -- der Snapshot haelt
    // Kennungen und den Zeitpunkt, nicht eine Beziehung.
    alsMandant(organisation('Demo-Praxis'));
    $kontakt = Contact::factory()->create();

    beruehrungskette($kontakt, [
        ['tage' => 2, 'quelle' => 'facebook', 'kampagne' => 'camp-1'],
    ]);

    $stand = app(Zuordnung::class)->standFuer($kontakt, CarbonImmutable::now()) ?? [];

    expect($stand)->not->toBeEmpty()
        ->and($stand['kampagne'] ?? null)->toBe('camp-1')
        ->and($stand['modell'] ?? null)->toBe('last_non_direct')
        ->and($stand)->toHaveKey('eingefroren_am');
});

it('verknuepft rueckwirkend alle Touches eines Besuchers', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    app(Beruehrungen::class)->erfasse(mitEinwilligung(['utm_source' => 'facebook']));
    app(Beruehrungen::class)->erfasse(mitEinwilligung());

    $kontakt = Contact::factory()->create();

    $betroffen = app(Beruehrungen::class)->verknuepfe('besucher-1', $kontakt);

    expect($betroffen)->toBe(2)
        ->and(AttributionTouch::query()->whereNull('contact_id')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Regeln
|--------------------------------------------------------------------------
*/

it('zeigt keiner Praxis die Beruehrungen einer anderen', function (): void {
    alsMandant(organisation('Praxis A'));
    app(Beruehrungen::class)->erfasse(mitEinwilligung(['utm_source' => 'facebook']));

    alsMandant(organisation('Praxis B'));

    expect(AttributionTouch::query()->count())->toBe(0);
});

it('raeumt Beruehrungen ohne Anfrage ab, verknuepfte aber nicht', function (): void {
    // Entscheidung P10: verknuepfte Touches bleiben, sonst reisst die
    // Verbindung zwischen Umsatz und Kampagne.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $kontakt = Contact::factory()->create();

    AttributionTouch::query()->create([
        'visitor_id' => 'ohne-anfrage',
        'occurred_at' => CarbonImmutable::now()->subDays(200),
    ]);

    AttributionTouch::query()->create([
        'visitor_id' => 'mit-anfrage',
        'occurred_at' => CarbonImmutable::now()->subDays(200),
        'contact_id' => $kontakt->getKey(),
    ]);

    $aufbewahrung = app(Aufbewahrung::class);
    $aufbewahrung->richteEin();

    $vorschau = $aufbewahrung->lauf(vorschau: true);

    expect($vorschau->nachGegenstand()[RetentionSubject::AttributionTouch->value] ?? 0)->toBe(1);

    $aufbewahrung->lauf(vorschau: false);

    expect(AttributionTouch::query()->count())->toBe(1)
        ->and(AttributionTouch::query()->first()?->visitor_id)->toBe('mit-anfrage');
});

it('speichert einen manuell angelegten Termin nicht ohne Quelle', function (): void {
    // **Testfall 6.** Ein Teil der Anzeigen-Leads ruft an oder kommt vorbei.
    // Ohne dieses Feld fehlen diese Buchungen, und der ROAS sieht schlechter
    // aus, als er ist.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $szenario = new Szenario;

    // Das Szenario baut um seine eigene Zeit -- ohne diesen Sprung liegt der
    // Vorschlag jenseits des Buchungshorizonts.
    travelTo($szenario->jetzt());

    $vorschlag = $szenario->vorschlag();

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->post(route('appointments.store'), [
            'appointment_type' => $szenario->aufbau->art->uuid,
            'practitioner' => $szenario->aufbau->behandler->uuid,
            'location' => $szenario->aufbau->standort->uuid,
            'blocked_from' => $vorschlag->blockedFrom->toIso8601String(),
            'contact' => $szenario->kontakt->uuid,
        ])
        ->assertSessionHasErrors('quelle');

    expect(Appointment::query()->count())->toBe(0);

    // Mit Quelle geht es -- und der Lead traegt sie.
    actingAs($benutzer)
        ->post(route('appointments.store'), [
            'appointment_type' => $szenario->aufbau->art->uuid,
            'practitioner' => $szenario->aufbau->behandler->uuid,
            'location' => $szenario->aufbau->standort->uuid,
            'blocked_from' => $vorschlag->blockedFrom->toIso8601String(),
            'contact' => $szenario->kontakt->uuid,
            'quelle' => LeadSource::Phone->value,
        ])
        ->assertSessionHasNoErrors();

    $lead = Lead::query()->where('contact_id', $szenario->kontakt->getKey())->latest('created_at')->first();

    expect(Appointment::query()->count())->toBe(1)
        ->and($lead?->source)->toBe(LeadSource::Phone);
});
