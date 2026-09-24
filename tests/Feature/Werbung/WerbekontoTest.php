<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Jobs\WerbestrukturAbgleichen;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\Location;
use App\Models\Treatment;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Werbung\Kontenauswahl;
use App\Werbung\Meta\Werbezugang;
use App\Werbung\Strukturabgleich;
use App\Werbung\Verwaltung\Standortaufloesung;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Werbung\Werbeaufbau;

/*
|--------------------------------------------------------------------------
| WP-26 -- Werbekonto-Anbindung & Sync
|--------------------------------------------------------------------------
|
| Die Abnahmekriterien aus specs/WP-26-werbekonto-anbindung.md, in der
| Reihenfolge des Briefings.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-17 09:00:00', 'UTC'));
    config()->set('mrs.meta.graph_url', 'https://graph.test');
    config()->set('mrs.meta.api_version', 'v21.0');
});

/*
|--------------------------------------------------------------------------
| Verbindung
|--------------------------------------------------------------------------
*/

it('zeigt ohne Werbekonto eine Einladung und keinen Fehler', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    actingAs(Werbeaufbau::leitung($organisation))
        ->get(route('werbung.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('werbung/Index')
            ->where('konto', null)
            ->where('kampagnen', [])
        );
});

it('legt aus dem Rueckweg eine Verbindung an, ohne das Token preiszugeben', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    Http::fake([
        'graph.test/*/oauth/access_token*' => Http::response(['access_token' => 'geheim-1', 'expires_in' => 5184000]),
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::seite([[
            'id' => 'act_1',
            'name' => 'Praxis Werbung',
            'currency' => 'EUR',
            'timezone_name' => 'Europe/Berlin',
            'account_status' => 1,
            'business' => ['id' => '99'],
        ]])),
    ]);

    $zugang = app(Werbezugang::class)->tausche('code-1');
    $angaben = app(Kontenauswahl::class)->verfuegbare($zugang->zugang);
    $konto = app(Kontenauswahl::class)->verbinde($angaben[0], $zugang);

    expect($konto->external_id)->toBe('act_1')
        ->and($konto->currency)->toBe('EUR')
        ->and($konto->istVerbunden())->toBeTrue()
        ->and($konto->access_token)->toBe('geheim-1')
        // Verschluesselt in der Datenbank, nicht im Klartext.
        ->and(DB::table('ad_accounts')->value('access_token'))->not->toBe('geheim-1')
        // Und nicht in einer Antwort: $hidden greift in toArray().
        ->and($konto->toArray())->not->toHaveKey('access_token');
});

it('nennt beim gescheiterten Rueckweg Metas Grund', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    // **Ein Rueckweg, der immer gleich klingt, ist keine Auskunft.** Bis zum
    // 24.09.2026 warf der Controller die Einordnung weg und zeigte
    // "Bitte erneut versuchen" -- was jemanden in denselben Versuch mit
    // demselben Ausgang schickt.
    Http::fake([
        'graph.test/*/oauth/access_token*' => Http::response(
            Werbeaufbau::fehler(100, 0, 'Invalid OAuth redirect URI.'),
            400
        ),
    ]);

    $state = Crypt::encryptString((string) json_encode([
        'organisation' => (string) $organisation->uuid,
        'zeitpunkt' => CarbonImmutable::now()->getTimestamp(),
    ]));

    actingAs(Werbeaufbau::leitung($organisation))
        ->get(route('werbung.rueckkehr', ['code' => 'code-1', 'state' => $state]))
        ->assertRedirect(route('werbung.index'))
        ->assertSessionHas('fehler', fn (string $meldung): bool => str_contains($meldung, 'Invalid OAuth redirect URI.'));
});

it('unterscheidet den Codetausch vom Lesen der Werbekonten', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    // Die Anmeldung glueckt, das Lesen der Konten nicht. Frueher ergaben
    // beide denselben Satz -- und schickten damit zur falschen Stelle.
    Http::fake([
        'graph.test/*/oauth/access_token*' => Http::response(['access_token' => 'geheim-1']),
        'graph.test/*/me/adaccounts*' => Http::response(
            Werbeaufbau::fehler(100, 0, 'The user has not granted ads_management.'),
            400
        ),
        // Auch der zweite Weg traegt nicht: dann gilt Metas Grund.
        'graph.test/*/debug_token*' => Http::response(
            Werbeaufbau::fehler(100, 0, 'The user has not granted ads_management.'),
            400
        ),
    ]);

    $state = Crypt::encryptString((string) json_encode([
        'organisation' => (string) $organisation->uuid,
        'zeitpunkt' => CarbonImmutable::now()->getTimestamp(),
    ]));

    actingAs(Werbeaufbau::leitung($organisation))
        ->get(route('werbung.rueckkehr', ['code' => 'code-1', 'state' => $state]))
        ->assertSessionHas('fehler', fn (string $meldung): bool => str_contains($meldung, 'Werbekonten')
            && str_contains($meldung, 'ads_management'));
});

it('nennt eine fehlende Freigabe beim Namen, statt zum Wiederholen zu raten', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    // Code 200: der Zugang steht, die Werberechte fehlen. Metas Antwort
    // traegt dafuer keinen Klartext -- und "bitte erneut versuchen" ist hier
    // schlicht falsch, Wiederholen hilft nicht.
    // Beide Kanten weisen ab: dann fehlt die Freigabe wirklich, und es liegt
    // nicht an der Art des Tokens.
    Http::fake([
        'graph.test/*/oauth/access_token*' => Http::response(['access_token' => 'geheim-1']),
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::fehler(200), 403),
        'graph.test/*/debug_token*' => Http::response(Werbeaufbau::fehler(200), 403),
    ]);

    $state = Crypt::encryptString((string) json_encode([
        'organisation' => (string) $organisation->uuid,
        'zeitpunkt' => CarbonImmutable::now()->getTimestamp(),
    ]));

    actingAs(Werbeaufbau::leitung($organisation))
        ->get(route('werbung.rueckkehr', ['code' => 'code-1', 'state' => $state]))
        ->assertSessionHas('fehler', fn (string $meldung): bool => str_contains($meldung, 'Werbekonten')
            && str_contains($meldung, 'ads_management')
            && ! str_contains($meldung, 'Bitte erneut versuchen'));
});

it('haelt einen abgelehnten Lesezugriff im Protokoll fest', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $eintraege = [];
    Log::listen(function (MessageLogged $eintrag) use (&$eintraege): void {
        $eintraege[] = $eintrag->context;
    });

    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::fehler(200, 1349174, 'Permissions error'), 403),
    ]);

    expect(fn () => app(Kontenauswahl::class)->verfuegbare('token'))->toThrow(Werbefehler::class);

    // Ohne Code und Subcode ist weder Metas Doku noch Metas Support zu
    // durchsuchen -- der Schreiber haelt sie seit je fest, der Leser bis zum
    // 24.09.2026 nicht.
    expect($eintraege)->toHaveCount(1)
        ->and($eintraege[0]['code'] ?? null)->toBe(200)
        ->and($eintraege[0]['subcode'] ?? null)->toBe(1349174);
});

it('findet die Konten eines Systemnutzer-Tokens ueber die Freigaben', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    // **Ein Systemnutzer-Token kennt `me` nicht.** Meta antwortet darauf mit
    // Code 200 -- das sieht aus wie ein Rechteproblem und ist keines. Wofuer
    // der Zugang gilt, sagt `debug_token`.
    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::fehler(200), 403),
        'graph.test/*/debug_token*' => Http::response(['data' => [
            'type' => 'SYSTEM_USER',
            'is_valid' => true,
            'scopes' => ['ads_management', 'ads_read'],
            'granular_scopes' => [
                // Meta nennt die Ziele ohne `act_`.
                ['scope' => 'ads_management', 'target_ids' => ['1']],
                ['scope' => 'pages_show_list', 'target_ids' => ['77']],
            ],
        ]]),
        'graph.test/*/act_1*' => Http::response([
            'id' => 'act_1',
            'name' => 'Praxis Werbung',
            'currency' => 'EUR',
            'timezone_name' => 'Europe/Berlin',
            'account_status' => 1,
            'business' => ['id' => '99'],
        ]),
    ]);

    $konten = app(Kontenauswahl::class)->verfuegbare('systemnutzer-token');

    expect($konten)->toHaveCount(1)
        ->and($konten[0]->kennung)->toBe('act_1')
        ->and($konten[0]->business)->toBe('99')
        ->and($konten[0]->nutzbar)->toBeTrue();

    // Eine Seitenfreigabe ist kein Werbekonto.
    Http::assertNotSent(fn ($anfrage): bool => str_contains((string) $anfrage->url(), 'act_77'));
});

it('fragt debug_token mit dem App-Token, nicht mit dem Zugang', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::fehler(200), 403),
        'graph.test/*/debug_token*' => Http::response(['data' => [
            'granular_scopes' => [['scope' => 'ads_read', 'target_ids' => ['1']]],
        ]]),
        'graph.test/*/act_1*' => Http::response(['id' => 'act_1', 'account_status' => 1]),
    ]);

    app(Kontenauswahl::class)->verfuegbare('systemnutzer-token');

    // Der Zugang steht in `input_token`, gefragt wird als App -- andersherum
    // beantwortet Meta die Frage nicht.
    Http::assertSent(fn ($anfrage): bool => str_contains((string) $anfrage->url(), 'debug_token')
        && str_contains((string) $anfrage->url(), 'input_token=systemnutzer-token')
        && str_contains((string) $anfrage->header('Authorization')[0], '|'));
});

it('findet die Konten ueber die Zuweisung des Systemnutzers', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    // Meta fuellt je nach Konfiguration mal `granular_scopes`, mal nur die
    // Zuweisung am Systemnutzer. Traegt die erste Stelle nicht, gilt die
    // zweite -- sonst steht ein korrekt ausgewaehltes Werbekonto da und
    // niemand findet es.
    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::fehler(200), 403),
        'graph.test/*/debug_token*' => Http::response(['data' => [
            'type' => 'SYSTEM_USER',
            'profile_id' => '4711',
            'is_valid' => true,
            'scopes' => ['ads_management'],
            'granular_scopes' => [],
        ]]),
        'graph.test/*/4711/assigned_ad_accounts*' => Http::response(Werbeaufbau::seite([[
            'id' => 'act_1',
            'name' => 'Praxis Werbung',
            'currency' => 'EUR',
            'account_status' => 1,
        ]])),
    ]);

    $konten = app(Kontenauswahl::class)->verfuegbare('systemnutzer-token');

    expect($konten)->toHaveCount(1)
        ->and($konten[0]->kennung)->toBe('act_1');
});

it('traegt Metas Auskunft in die Meldung, wenn kein Weg traegt', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::fehler(200), 403),
        'graph.test/*/debug_token*' => Http::response(['data' => [
            'type' => 'SYSTEM_USER',
            'profile_id' => '4711',
            'scopes' => ['ads_management'],
            'granular_scopes' => [],
        ]]),
        'graph.test/*/4711/assigned_ad_accounts*' => Http::response(Werbeaufbau::fehler(200), 403),
    ]);

    try {
        app(Kontenauswahl::class)->verfuegbare('systemnutzer-token');
        expect(false)->toBeTrue('Es haette ein Werbefehler kommen muessen.');
    } catch (Werbefehler $fehler) {
        // Ohne die Auskunft in der Meldung braucht jede weitere Runde den
        // Log-Stream -- und genau daran hing es drei Runden lang.
        expect((string) $fehler->einordnung->klartext)->toContain('SYSTEM_USER')
            ->and((string) $fehler->einordnung->klartext)->toContain('4711');
    }
});

it('sagt, wenn Meta zum Zugang kein Werbekonto nennt', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    // Der Zugang steht, die Freigaben stehen -- nur ist im Anmeldedialog
    // kein Werbekonto angehakt worden. "Kein Werbekonto freigegeben" allein
    // schickt niemanden dorthin.
    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::fehler(200), 403),
        'graph.test/*/debug_token*' => Http::response(['data' => [
            'type' => 'SYSTEM_USER',
            'profile_id' => '4711',
            'is_valid' => true,
            'scopes' => ['ads_read', 'ads_management'],
            'granular_scopes' => [['scope' => 'pages_show_list', 'target_ids' => ['77']]],
        ]]),
        'graph.test/*/4711/assigned_ad_accounts*' => Http::response(Werbeaufbau::seite([])),
    ]);

    try {
        app(Kontenauswahl::class)->verfuegbare('systemnutzer-token');
        expect(false)->toBeTrue('Es haette ein Werbefehler kommen muessen.');
    } catch (Werbefehler $fehler) {
        expect((string) $fehler->einordnung->klartext)->toContain('kein Werbekonto zugeordnet')
            ->and((string) $fehler->einordnung->klartext)->toContain('ads_management')
            // Eine Seitenfreigabe ist kein Werbekonto -- aber sie steht in
            // der Auskunft, damit sichtbar ist, was Meta stattdessen nennt.
            ->and((string) $fehler->einordnung->klartext)->toContain('pages_show_list');
    }
});

it('fragt die Freigaben nicht ab, wenn die erste Kante antwortet', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    // Ein Nutzertoken ohne Werbekonto ist kein Fehler -- und keine leere
    // Antwort, die man an einer zweiten Kante nachschlagen muesste.
    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::seite([])),
    ]);

    expect(app(Kontenauswahl::class)->verfuegbare('nutzer-token'))->toBe([]);

    Http::assertNotSent(fn ($anfrage): bool => str_contains((string) $anfrage->url(), 'debug_token'));
});

it('fragt bei einem toten Token die Freigaben nicht ab', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    // 190: das Token ist tot. Auf dem zweiten Weg wird es nicht lebendiger,
    // und ein zweiter Aufruf verdeckte nur den eigentlichen Grund.
    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::fehler(190), 401),
    ]);

    expect(fn () => app(Kontenauswahl::class)->verfuegbare('totes-token'))->toThrow(Werbefehler::class);

    Http::assertNotSent(fn ($anfrage): bool => str_contains((string) $anfrage->url(), 'debug_token'));
});

it('waehlt bei mehreren Werbekonten, statt zu raten', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    Http::fake([
        'graph.test/*/me/adaccounts*' => Http::response(Werbeaufbau::seite([
            ['id' => 'act_1', 'name' => 'Praxis', 'account_status' => 1],
            ['id' => 'act_2', 'name' => 'Fremde Praxis', 'account_status' => 2],
        ])),
    ]);

    $konten = app(Kontenauswahl::class)->verfuegbare('token');

    expect($konten)->toHaveCount(2)
        ->and($konten[0]->nutzbar)->toBeTrue()
        // account_status 2 ist gesperrt: anbieten ja, unbemerkt nehmen nein.
        ->and($konten[1]->nutzbar)->toBeFalse()
        ->and(AdAccount::query()->count())->toBe(0);
});

it('setzt bei abgelaufenem Token expired und wiederholt nicht', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake(['graph.test/*' => Http::response(Werbeaufbau::fehler(190), 401)]);

    try {
        app(Strukturabgleich::class)->gleicheAb($aufbau->konto);
        $this->fail('Der Abgleich haette werfen muessen.');
    } catch (Werbefehler $fehler) {
        expect($fehler->einordnung->kurzgrund)->toBe('token_invalid')
            ->and($fehler->einordnung->wiederholen)->toBeFalse()
            ->and($fehler->einordnung->zustand)->toBe(ConnectionStatus::Expired);
    }
});

it('unterscheidet eine fehlende Berechtigung von einem toten Token', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake(['graph.test/*' => Http::response(Werbeaufbau::fehler(200), 403)]);

    try {
        app(Strukturabgleich::class)->gleicheAb($aufbau->konto);
        $this->fail('Der Abgleich haette werfen muessen.');
    } catch (Werbefehler $fehler) {
        // Bei einem toten Token hilft ein neues, hier hilft nur eine
        // Freigabe -- deshalb zwei Zustaende und zwei Hinweise.
        expect($fehler->einordnung->kurzgrund)->toBe('permission_missing')
            ->and($fehler->einordnung->zustand)->toBe(ConnectionStatus::Degraded);
    }
});

it('haelt bei einem gesperrten Werbekonto an und zeigt es im Produkt', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake(['graph.test/*' => Http::response(Werbeaufbau::fehler(368), 400)]);

    Queue::fake();
    (new WerbestrukturAbgleichen((string) $aufbau->organisation->uuid, (string) $aufbau->konto->uuid))
        ->handle(app(TenantContext::class), app(Strukturabgleich::class));

    alsMandant($aufbau->organisation);

    expect($aufbau->konto->fresh()?->status)->toBe(ConnectionStatus::Suspended);

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->get(route('werbung.index'))
        ->assertInertia(fn ($seite) => $seite
            ->where('konto.zustand', 'suspended')
            ->where('konto.grund', 'suspended')
        );
});

it('trennt das Token, behaelt aber die gelesene Struktur', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake([
        'graph.test/*/campaigns*' => Http::response(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Herbst')])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    app(Kontenauswahl::class)->trenne($aufbau->konto);

    $frisch = $aufbau->konto->fresh();

    expect($frisch?->istVerbunden())->toBeFalse()
        ->and($frisch?->getAttributes()['access_token'])->toBeNull()
        // Die Kampagne bleibt: an ihr haengt ab WP-32 die Attribution.
        ->and(AdCampaign::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Abgleich
|--------------------------------------------------------------------------
*/

it('legt die drei Ebenen an und beim zweiten Lauf nichts doppelt', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake([
        'graph.test/*/campaigns*' => Http::response(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Herbst')])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([Werbeaufbau::gruppe('s1', 'Frauen 30+', 'c1')])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([Werbeaufbau::anzeige('a1', 'Motiv A', 's1')])),
    ]);

    $erste = app(Strukturabgleich::class)->gleicheAb($aufbau->konto);
    $zweite = app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    expect($erste->angelegt)->toBe(3)
        ->and($zweite->angelegt)->toBe(0)
        ->and($zweite->geaendert)->toBe(0)
        ->and(AdCampaign::query()->count())->toBe(1)
        ->and(AdSet::query()->count())->toBe(1)
        ->and(Ad::query()->count())->toBe(1)
        ->and(Ad::query()->first()?->creative_external_id)->toBe('creative-a1');
});

it('liest eine Antwort ueber mehrere Seiten vollstaendig', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Seite eins')], 'https://graph.test/weiter'))
            ->push(Werbeaufbau::seite([Werbeaufbau::kampagne('c2', 'Seite zwei')])),
        'graph.test/weiter*' => Http::response(Werbeaufbau::seite([Werbeaufbau::kampagne('c2', 'Seite zwei')])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    expect(AdCampaign::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Metas eigene Folgeadresse ist nicht vertrauenswuerdig
|--------------------------------------------------------------------------
|
| `/search` liefert **immer** einen next-Cursor, auch wenn nichts folgt. Und
| diese Adresse traegt zwei Eigenschaften, die den zweiten Aufruf zerstoeren:
| sie zeigt auf Metas *aktuelle* Version statt auf die festgenagelte, und die
| Parameter sind doppelt kodiert (`%255B%2522` statt `%5B%22`). Meta antwortet
| darauf mit "Invalid parameter".
|
| Am 23.09.2026 hat das jede Ortsaufloesung scheitern lassen -- und damit
| jede Anzeigengruppe und jede Anzeige. Die erste Seite hatte das Ergebnis
| laengst; der zweite Aufruf machte es zunichte.
|
| Die Folgeseite wird deshalb selbst gebaut: eigener Grundpfad, eigene
| Parameter, nur der Cursor kommt von Meta.
|
*/

it('baut die Folgeseite selbst, statt Metas Adresse zu folgen', function (): void {
    new Werbeaufbau;
    $standort = Location::factory()->create(['city' => 'Hamburg', 'country' => 'DE', 'is_active' => true]);

    Http::fake([
        'graph.test/*/search*' => Http::sequence()
            ->push([
                'data' => [['key' => '560419', 'name' => 'Hamburg', 'type' => 'city']],
                'paging' => [
                    'cursors' => ['before' => 'MAZDZD', 'after' => 'NAZDZD'],
                    'next' => 'https://graph.facebook.com/v26.0/search?location_types=%255B%2522city%2522%255D&after=NAZDZD',
                ],
            ])
            ->push(['data' => []]),
        '*' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400),
    ]);

    $kennung = app(Standortaufloesung::class)->kennung($standort, 'token');

    expect($kennung)->toBe('560419')
        // Einmal gefunden, nie wieder gesucht.
        ->and($standort->fresh()?->meta_city_key)->toBe('560419');

    // Metas Adresse traegt eine fremde Version -- die darf nie aufgerufen werden.
    Http::assertNotSent(fn ($anfrage): bool => str_contains((string) $anfrage->url(), 'v26.0'));
});

it('folgt Metas Adresse weiterhin, wenn kein Cursor dabei ist', function (): void {
    $aufbau = new Werbeaufbau;

    // Aeltere Kanten liefern nur `next`. Dort bleibt es beim alten Weg --
    // sonst bliebe die zweite Seite ungelesen.
    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Seite eins')], 'https://graph.test/weiter'))
            ->push(Werbeaufbau::seite([])),
        'graph.test/weiter*' => Http::response(Werbeaufbau::seite([Werbeaufbau::kampagne('c2', 'Seite zwei')])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    expect(AdCampaign::query()->count())->toBe(2);
});

it('bricht ein Paging ab, das sich im Kreis dreht', function (): void {
    $aufbau = new Werbeaufbau;

    // Dieselbe Folgeadresse immer wieder: ohne Abbruch laeuft der Abgleich
    // nicht zu Ende und blockiert die Warteschlange fuer alle anderen.
    Http::fake([
        'graph.test/*' => Http::response(
            Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Immer dieselbe')], 'https://graph.test/immer')
        ),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    expect(AdCampaign::query()->count())->toBe(1);
});

it('markiert, was bei Meta verschwindet, statt es zu loeschen', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Herbst'), Werbeaufbau::kampagne('c2', 'Winter')]))
            ->push(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Herbst')])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);
    $zweite = app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    expect($zweite->verschwunden)->toBe(1)
        ->and(AdCampaign::query()->count())->toBe(2)
        ->and(AdCampaign::query()->where('external_id', 'c2')->first()?->vanished_at)->not->toBeNull()
        ->and(AdCampaign::query()->vorhanden()->count())->toBe(1);
});

it('speichert Metas Zeitstempel in UTC', function (): void {
    // Meta schickt Ortszeit mit Versatz. Ohne Umrechnung landet 08:00 als
    // 08:00 UTC in der Spalte -- zwei Stunden daneben, und weil der gelesene
    // Wert dann nie dem gesendeten gleicht, meldet jeder naechtliche Lauf
    // dieselbe Kampagne erneut als geaendert.
    $aufbau = new Werbeaufbau;

    Http::fake([
        'graph.test/*/campaigns*' => Http::response(Werbeaufbau::seite([
            Werbeaufbau::kampagne('c1', 'Herbst', ['start_time' => '2026-09-01T08:00:00+0200']),
        ])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    expect(DB::table('ad_campaigns')->value('starts_at'))->toBe('2026-09-01 06:00:00');
});

it('uebernimmt eine Umbenennung bei Meta', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Herbst')]))
            ->push(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Herbst neu', ['status' => 'PAUSED'])])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);
    $zweite = app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    expect($zweite->geaendert)->toBe(1)
        ->and(AdCampaign::query()->first()?->name)->toBe('Herbst neu')
        ->and(AdCampaign::query()->first()?->status)->toBe('PAUSED');
});

it('wiederholt ein Rate-Limit, ein totes Token dagegen nicht', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake(['graph.test/*' => Http::response(Werbeaufbau::fehler(17), 429)]);

    try {
        app(Strukturabgleich::class)->gleicheAb($aufbau->konto);
        $this->fail('Der Abgleich haette werfen muessen.');
    } catch (Werbefehler $fehler) {
        expect($fehler->einordnung->kurzgrund)->toBe('rate_limit')
            ->and($fehler->einordnung->wiederholen)->toBeTrue()
            // Kein Zustand: ein Rate-Limit ist keine kaputte Verbindung.
            ->and($fehler->einordnung->zustand)->toBeNull();
    }
});

it('stellt den Abgleich ein, statt Meta im Request aufzurufen', function (): void {
    $aufbau = new Werbeaufbau;

    Queue::fake();
    Http::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.abgleichen', ['werbekonto' => $aufbau->konto->uuid]))
        ->assertRedirect();

    Queue::assertPushed(WerbestrukturAbgleichen::class);

    // Entscheidung B2: kein Fremdsystemaufruf im Anfragezyklus.
    Http::assertNothingSent();
});

it('laedt bei einer Stoerung mit dem letzten Stand statt mit einem Fehler', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake([
        'graph.test/*/campaigns*' => Http::response(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Herbst')])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);
    $aufbau->konto->meldeAusfall(ConnectionStatus::Expired, 'token_invalid');

    Http::fake(['graph.test/*' => Http::response([], 500)]);

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->get(route('werbung.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->where('konto.zustand', 'expired')
            ->where('konto.grund', 'token_invalid')
            ->has('kampagnen', 1)
        );
});

/*
|--------------------------------------------------------------------------
| Regeln
|--------------------------------------------------------------------------
*/

it('legt Kampagnennamen verschluesselt ab', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake([
        'graph.test/*/campaigns*' => Http::response(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Botox Herbst')])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([Werbeaufbau::gruppe('s1', 'Zielgruppe A', 'c1')])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([Werbeaufbau::anzeige('a1', 'Motiv A', 's1')])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    // Ab WP-32 friert dieser Name am Termin ein (D13) -- dann stuende ein
    // Behandlungsname in einem offenen Feld neben einem Kontakt.
    foreach ([['ad_campaigns', 'Botox Herbst'], ['ad_sets', 'Zielgruppe A'], ['ads', 'Motiv A']] as [$tabelle, $klartext]) {
        expect(DB::table($tabelle)->value('name'))->not->toBe($klartext);
    }

    expect(AdCampaign::query()->first()?->name)->toBe('Botox Herbst');
});

it('weist einen importierten Namen mit Katalogbezeichnung aus', function (): void {
    $aufbau = new Werbeaufbau;

    Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);
    Treatment::factory()->create(['name' => 'Microneedling', 'is_active' => true]);

    Http::fake([
        'graph.test/*/campaigns*' => Http::response(Werbeaufbau::seite([
            Werbeaufbau::kampagne('c1', 'Botox Herbst 2026'),
            Werbeaufbau::kampagne('c2', 'Herbstaktion'),
        ])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($aufbau->konto);

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->get(route('werbung.index'))
        ->assertInertia(function ($seite): void {
            /** @var list<array<string, mixed>> $zeilen */
            $zeilen = $seite->toArray()['props']['kampagnen'];

            $nachKennung = [];

            foreach ($zeilen as $zeile) {
                $nachKennung[(string) $zeile['kennung']] = $zeile;
            }

            expect($nachKennung['c1']['katalogtreffer'])->toBe('Botox')
                // Der Name bleibt, wie die Praxis ihn gewaehlt hat (C9).
                ->and($nachKennung['c1']['name'])->toBe('Botox Herbst 2026')
                ->and($nachKennung['c2']['katalogtreffer'])->toBeNull();
        });
});

/*
|--------------------------------------------------------------------------
| Die Facebook-Seite
|--------------------------------------------------------------------------
|
| Sie ist der Absender jeder Anzeige und wird abgetippt: sie zu lesen
| braeuchte `pages_show_list`, und jede Berechtigung mehr verzoegert den App
| Review (WP-27b). Ohne sie lehnt Meta jedes Creative ab -- deshalb gibt es
| ein Feld dafuer, und deshalb muss es auch bedienbar sein.
|
*/

it('hinterlegt die Facebook-Seite am Werbekonto', function (): void {
    $aufbau = new Werbeaufbau;

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->patch(route('werbung.seite', ['werbekonto' => $aufbau->konto->uuid]), ['seite' => '102938475610293'])
        ->assertSessionHas('erfolg');

    expect($aufbau->konto->fresh()?->page_external_id)->toBe('102938475610293');
});

it('nimmt als Seiten-ID nur Ziffern an', function (): void {
    $aufbau = new Werbeaufbau;

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->patch(route('werbung.seite', ['werbekonto' => $aufbau->konto->uuid]), ['seite' => 'meine-praxis'])
        ->assertSessionHasErrors('seite');

    expect($aufbau->konto->fresh()?->page_external_id)->toBeNull();
});

it('laesst die Facebook-Seite wieder loeschen', function (): void {
    $aufbau = new Werbeaufbau;
    $aufbau->konto->page_external_id = '778899';
    $aufbau->konto->save();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->patch(route('werbung.seite', ['werbekonto' => $aufbau->konto->uuid]), ['seite' => ''])
        ->assertSessionHas('erfolg');

    expect($aufbau->konto->fresh()?->page_external_id)->toBeNull();
});

it('gibt die hinterlegte Seite an die Oberflaeche', function (): void {
    $aufbau = new Werbeaufbau;
    $aufbau->konto->page_external_id = '778899';
    $aufbau->konto->save();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->get(route('werbung.index'))
        ->assertInertia(fn ($seite) => $seite->where('konto.seite', '778899'));
});

it('laesst niemanden ohne campaigns.manage die Facebook-Seite setzen', function (): void {
    $aufbau = new Werbeaufbau;
    $mitarbeiterin = User::factory()->fuer($aufbau->organisation, Role::Reception)->create();

    actingAs($mitarbeiterin)
        ->patch(route('werbung.seite', ['werbekonto' => $aufbau->konto->uuid]), ['seite' => '102938475610293'])
        ->assertForbidden();

    expect($aufbau->konto->fresh()?->page_external_id)->toBeNull();
});

it('laesst niemanden ohne campaigns.manage an die Werbung', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $mitarbeiterin = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($mitarbeiterin)->get(route('werbung.index'))->assertForbidden();
});

it('zeigt keiner Praxis die Struktur einer anderen', function (): void {
    $eine = new Werbeaufbau(organisation('Praxis A'));

    Http::fake([
        'graph.test/*/campaigns*' => Http::response(Werbeaufbau::seite([Werbeaufbau::kampagne('c1', 'Nur A')])),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/ads*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(Strukturabgleich::class)->gleicheAb($eine->konto);

    $andere = alsMandant(organisation('Praxis B'));

    expect(AdCampaign::query()->count())->toBe(0)
        ->and(AdAccount::query()->count())->toBe(0);

    actingAs(Werbeaufbau::leitung($andere))
        ->get(route('werbung.index'))
        ->assertInertia(fn ($seite) => $seite->where('konto', null)->where('kampagnen', []));
});
