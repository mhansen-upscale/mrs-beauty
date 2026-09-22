<?php

declare(strict_types=1);

use App\Enums\SyncState;
use App\Enums\Vorschlagsstatus;
use App\Jobs\AnzeigeUebertragen;
use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Treatment;
use App\Tenancy\TenantContext;
use App\Werbung\Verwaltung\Anzeigenschaltung;
use App\Werbung\Verwaltung\Kampagnenname;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Werbung\Anzeigenaufbau;
use Tests\Feature\Werbung\Werbeaufbau;

/*
|--------------------------------------------------------------------------
| WP-27b -- Anzeigen schalten
|--------------------------------------------------------------------------
|
| Der letzte Meter: aus einem freigegebenen Entwurf mit Grafik wird eine
| Anzeige bei Meta. Alles davor steht in WP-30 und WP-31.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-21 09:00:00', 'UTC'));
    config()->set('mrs.meta.graph_url', 'https://graph.test');
    config()->set('mrs.meta.api_version', 'v21.0');
});

/*
|--------------------------------------------------------------------------
| Was sich schalten laesst
|--------------------------------------------------------------------------
*/

it('schaltet nur einen freigegebenen Entwurf', function (): void {
    $aufbau = new Anzeigenaufbau;
    $vorschlag = $aufbau->vorschlagMitGrafik(Vorschlagsstatus::Entwurf);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->post(route('anzeigen.schalten', ['vorschlag' => $vorschlag->uuid]), [
            'kampagne' => (string) $aufbau->kampagne->uuid,
        ])
        ->assertSessionHas('fehler');

    expect(Ad::query()->count())->toBe(0);
});

it('schaltet keinen Entwurf ohne Grafik', function (): void {
    $aufbau = new Anzeigenaufbau;
    $vorschlag = $aufbau->vorschlagOhneGrafik();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->post(route('anzeigen.schalten', ['vorschlag' => $vorschlag->uuid]), [
            'kampagne' => (string) $aufbau->kampagne->uuid,
        ])
        ->assertSessionHas('fehler');

    expect(Ad::query()->count())->toBe(0);
});

it('legt lokal an und stellt einen Auftrag ein, ohne Meta zu rufen', function (): void {
    $aufbau = new Anzeigenaufbau;
    $vorschlag = $aufbau->vorschlagMitGrafik();

    Queue::fake();
    Http::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->post(route('anzeigen.schalten', ['vorschlag' => $vorschlag->uuid]), [
            'kampagne' => (string) $aufbau->kampagne->uuid,
        ])
        ->assertSessionHas('erfolg');

    $anzeige = Ad::query()->firstOrFail();

    // **Nie im Anfragezyklus** (Regel 4) -- und pausiert, wie die Kampagne.
    Http::assertNothingSent();
    Queue::assertPushed(AnzeigeUebertragen::class);

    expect($anzeige->status)->toBe('PAUSED')
        ->and($anzeige->sync_state)->toBe(SyncState::Pending)
        ->and($anzeige->managed_by_us)->toBeTrue()
        ->and($anzeige->getAttributes()['ad_suggestion_id'])->not->toBeNull();
});

it('schaltet denselben Entwurf nicht zweimal in dieselbe Kampagne', function (): void {
    $aufbau = new Anzeigenaufbau;
    $vorschlag = $aufbau->vorschlagMitGrafik();

    Queue::fake();

    $leitung = Werbeaufbau::leitung($aufbau->werbung->organisation);

    actingAs($leitung)->post(route('anzeigen.schalten', ['vorschlag' => $vorschlag->uuid]), [
        'kampagne' => (string) $aufbau->kampagne->uuid,
    ]);

    actingAs($leitung)->post(route('anzeigen.schalten', ['vorschlag' => $vorschlag->uuid]), [
        'kampagne' => (string) $aufbau->kampagne->uuid,
    ])->assertSessionHas('fehler');

    expect(Ad::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Die Uebertragung
|--------------------------------------------------------------------------
*/

it('laedt das Bild hoch, legt das Creative an und dann die Anzeige', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
        'graph.test/*/act_*/ads' => Http::response(['id' => 'anzeige-1']),
    ]);

    app(Anzeigenschaltung::class)->uebertrage($anzeige, $aufbau->werbung->konto);

    $frisch = $anzeige->fresh();

    expect($frisch?->external_id)->toBe('anzeige-1')
        ->and($frisch?->creative_external_id)->toBe('creative-1')
        ->and($frisch?->image_hash)->toBe('bildhash-1')
        ->and($frisch?->sync_state)->toBe(SyncState::Synced);

    // Pausiert angelegt: eine Anzeige, die im Moment des Anlegens ausliefert,
    // laesst keinen Blick darauf zu, bevor sie es tut.
    Http::assertSent(fn ($anfrage): bool => str_ends_with((string) $anfrage->url(), '/ads')
        && $anfrage->data()['status'] === 'PAUSED');
});

it('setzt keine Katalogbezeichnung in den Namen der Anzeige', function (): void {
    $aufbau = new Anzeigenaufbau;

    Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);
    Treatment::factory()->create(['name' => 'Faltenbehandlung', 'is_active' => true]);

    $anzeige = $aufbau->geplanteAnzeige('Faltenbehandlung in Ruhe besprochen');

    // **Regel 2 trennt Angebot und Person, nicht Wort und Wort** (C9): der
    // Inhalt darf die Leistung nennen, der Name nicht -- er liegt
    // unverschluesselt und friert am Termin ein.
    foreach (Treatment::aktiveNamen() as $behandlung) {
        expect((string) $anzeige->name)->not->toContain($behandlung);
    }

    expect(Kampagnenname::merkmalAus((string) $anzeige->name))->not->toBeNull();
});

it('legt bei einem zweiten Auftrag keine zweite Anzeige an', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    $merkmal = (string) $anzeige->client_token;

    // Metas Marketing-API kennt keinen Idempotenzschluessel -- erst
    // nachsehen, dann anlegen.
    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([
            ['id' => 'schon-da', 'name' => 'Anzeige ['.$merkmal.']'],
        ])),
    ]);

    app(Anzeigenschaltung::class)->uebertrage($anzeige, $aufbau->werbung->konto);

    expect($anzeige->fresh()?->external_id)->toBe('schon-da');

    Http::assertNotSent(fn ($anfrage): bool => str_ends_with((string) $anfrage->url(), '/adcreatives'));
});

/**
 * **Ohne `ads_management` bleibt die Absicht bestehen.**
 *
 * Eine fehlende Berechtigung ist kein Fehler der Anzeige: sobald sie da ist,
 * geht diese Anzeige hinaus. Der Hinweis haengt am Werbekonto, wo er
 * hingehoert -- ihn hier zu wiederholen hiesse, zweimal dasselbe zu sagen,
 * und beim zweiten Mal als Code.
 */
it('laesst eine Anzeige bei fehlender Berechtigung offen', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*' => Http::response([
            'error' => ['message' => 'Application does not have permission for this action', 'code' => 200],
        ], 403),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $frisch = $anzeige->fresh();

    expect($frisch?->sync_state)->toBe(SyncState::Pending)
        ->and($frisch?->sync_error)->toBeNull();
});

it('schaltet ohne hinterlegte Facebook-Seite nicht und sagt warum', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Der Aufbau hinterlegt eine Seite, weil ohne sie nichts geht. Genau das
    // ist hier der Fall: ein Werbekonto, dem noch keine zugeordnet wurde.
    $aufbau->werbung->konto->page_external_id = null;
    $aufbau->werbung->konto->save();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $frisch = $anzeige->fresh();

    // Nicht wiederholen: ein zweiter Lauf scheitert genauso, solange niemand
    // die Seite eintraegt. Der Satz steht deutsch an der Anzeige, weil die
    // Praxis das selbst beheben kann -- und nur sie.
    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        ->and($frisch?->sync_error)->toBe('Diesem Werbekonto ist keine Facebook-Seite zugeordnet. Ohne sie kann Meta keine Anzeige ausliefern.');

    // Ohne Seite wird kein Creative angelegt -- der Abbruch kommt davor.
    Http::assertNotSent(fn ($anfrage): bool => str_ends_with((string) $anfrage->url(), '/adcreatives'));
});

it('haelt eine fachliche Ablehnung im Klartext an der Anzeige fest', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*' => Http::response([
            'error' => ['message' => 'Das Bild ist zu klein für dieses Format.', 'code' => 100],
        ], 400),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $frisch = $anzeige->fresh();

    // Im Klartext, nicht als Code: Metas fachliche Ablehnungen sind das
    // Einzige, was die Praxis selbst beheben kann.
    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        ->and($frisch?->sync_error)->toBe('Das Bild ist zu klein für dieses Format.');
});

/*
|--------------------------------------------------------------------------
| Kampagne bearbeiten
|--------------------------------------------------------------------------
*/

it('aendert das Tagesbudget und uebertraegt es', function (): void {
    $aufbau = new Anzeigenaufbau;

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->patch(route('werbung.kampagne.aendern', ['kampagne' => $aufbau->kampagne->uuid]), [
            'tagesbudget' => 4000,
        ])
        ->assertSessionHas('erfolg');

    expect($aufbau->kampagne->fresh()?->daily_budget)->toBe(4000);
});

it('aendert die Zielgruppe der Anzeigengruppe', function (): void {
    $aufbau = new Anzeigenaufbau;

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->patch(route('werbung.kampagne.aendern', ['kampagne' => $aufbau->kampagne->uuid]), [
            'umkreis' => 25,
            'altervon' => 35,
            'alterbis' => 55,
            'geschlecht' => 'weiblich',
        ])
        ->assertSessionHas('erfolg');

    $gruppe = AdSet::query()->where('ad_campaign_id', $aufbau->kampagne->getKey())->firstOrFail();

    expect($gruppe->radius_km)->toBe(25)
        ->and($gruppe->age_min)->toBe(35)
        ->and($gruppe->age_max)->toBe(55)
        ->and($gruppe->genders)->toBe('weiblich')
        ->and($gruppe->sync_state)->toBe(SyncState::Pending);
});

it('laesst das Tagesbudget nicht unter Metas Untergrenze fallen', function (): void {
    $aufbau = new Anzeigenaufbau;

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->patch(route('werbung.kampagne.aendern', ['kampagne' => $aufbau->kampagne->uuid]), [
            'tagesbudget' => 10,
        ])
        ->assertSessionHasErrors('tagesbudget');
});

it('zeigt keiner Praxis die Anzeigen einer anderen', function (): void {
    $eine = new Anzeigenaufbau;
    $eine->geplanteAnzeige();

    expect(Ad::query()->count())->toBe(1);

    // Der Wechsel des Mandanten, nicht der des Benutzers: der globale Scope
    // haengt am Mandanten.
    new Anzeigenaufbau(organisation('Zweite Praxis'));

    expect(Ad::query()->count())->toBe(0);
});
