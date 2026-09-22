<?php

declare(strict_types=1);

use App\Datenschutz\Aufbewahrung;
use App\Enums\ConnectionStatus;
use App\Enums\InsightLevel;
use App\Enums\RetentionSubject;
use App\Jobs\WerbezahlenAbgleichen;
use App\Models\AdCampaign;
use App\Models\AdInsight;
use App\Werbung\Kennzahlen;
use App\Werbung\Kennzahlenabgleich;
use App\Werbung\Kennzahlensatz;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Werbung\Werbeaufbau;

/*
|--------------------------------------------------------------------------
| WP-28 -- Insights & Aggregation
|--------------------------------------------------------------------------
|
| Die Abnahmekriterien aus specs/WP-28-insights-aggregation.md.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-17 09:00:00', 'UTC'));
    config()->set('mrs.meta.graph_url', 'https://graph.test');
    config()->set('mrs.meta.api_version', 'v21.0');
    config()->set('mrs.ads.insights_window_days', 7);
});

/**
 * Eine Insights-Zeile, wie Meta sie liefert.
 *
 * @param  array<string, mixed>  $zusatz
 * @return array<string, mixed>
 */
function metazeile(string $kennungsfeld, string $kennung, string $tag, array $zusatz = []): array
{
    return array_merge([
        $kennungsfeld => $kennung,
        'date_start' => $tag,
        'date_stop' => $tag,
        'spend' => '25.43',
        'impressions' => '1200',
        'clicks' => '48',
        'inline_link_clicks' => '31',
        'actions' => [
            ['action_type' => 'lead', 'value' => '3'],
            ['action_type' => 'page_engagement', 'value' => '77'],
        ],
    ], $zusatz);
}

/**
 * Antworten fuer alle drei Ebenen.
 *
 * @param  list<array<string, mixed>>  $kampagnen
 * @param  list<array<string, mixed>>  $gruppen
 * @param  list<array<string, mixed>>  $anzeigen
 */
function insightsAntwort(array $kampagnen, array $gruppen = [], array $anzeigen = []): void
{
    Http::fake([
        'graph.test/*/insights*' => Http::sequence()
            ->push(Werbeaufbau::seite($kampagnen))
            ->push(Werbeaufbau::seite($gruppen))
            ->push(Werbeaufbau::seite($anzeigen)),
    ]);
}

/*
|--------------------------------------------------------------------------
| Lesen
|--------------------------------------------------------------------------
*/

it('legt Tageszeilen je Ebene an', function (): void {
    $aufbau = new Werbeaufbau;

    insightsAntwort(
        [metazeile('campaign_id', 'c1', '2026-09-16')],
        [metazeile('adset_id', 's1', '2026-09-16')],
        [metazeile('ad_id', 'a1', '2026-09-16')],
    );

    $bilanz = app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    expect($bilanz->zeilen)->toBe(3)
        ->and(AdInsight::query()->count())->toBe(3)
        ->and(AdInsight::query()->ebene(InsightLevel::Campaign)->count())->toBe(1)
        ->and(AdInsight::query()->ebene(InsightLevel::AdSet)->count())->toBe(1)
        ->and(AdInsight::query()->ebene(InsightLevel::Ad)->count())->toBe(1);
});

it('ueberschreibt statt zu verdoppeln', function (): void {
    $aufbau = new Werbeaufbau;

    // Eine Sequenz fuer beide Laeufe: ein zweites Http::fake auf dasselbe
    // Muster ersetzt das erste nicht.
    Http::fake([
        'graph.test/*/insights*' => Http::sequence()
            ->push(Werbeaufbau::seite([metazeile('campaign_id', 'c1', '2026-09-16')]))
            ->push(Werbeaufbau::seite([]))
            ->push(Werbeaufbau::seite([]))
            ->push(Werbeaufbau::seite([metazeile('campaign_id', 'c1', '2026-09-16')]))
            ->push(Werbeaufbau::seite([]))
            ->push(Werbeaufbau::seite([])),
    ]);

    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);
    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    expect(AdInsight::query()->count())->toBe(1);
});

it('uebernimmt nachtraeglich geaenderte Zahlen eines zurueckliegenden Tages', function (): void {
    // Metas Zuordnungsfenster wirkt rueckwirkend: die Zahlen eines Tages
    // bewegen sich bis zu 28 Tage lang.
    $aufbau = new Werbeaufbau;

    // **Eine Sequenz fuer beide Laeufe.** Ein zweites Http::fake auf dasselbe
    // Muster ersetzt das erste nicht, es wird verworfen -- derselbe
    // Fallstrick wie in WP-20a.
    Http::fake([
        'graph.test/*/insights*' => Http::sequence()
            ->push(Werbeaufbau::seite([metazeile('campaign_id', 'c1', '2026-09-12', ['spend' => '10.00', 'actions' => []])]))
            ->push(Werbeaufbau::seite([]))
            ->push(Werbeaufbau::seite([]))
            ->push(Werbeaufbau::seite([metazeile('campaign_id', 'c1', '2026-09-12', [
                'spend' => '10.00',
                'actions' => [['action_type' => 'lead', 'value' => '2']],
            ])]))
            ->push(Werbeaufbau::seite([]))
            ->push(Werbeaufbau::seite([])),
    ]);

    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    expect(AdInsight::query()->first()?->spend_minor)->toBe(1000)
        ->and(AdInsight::query()->first()?->leads)->toBe(0);

    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    expect(AdInsight::query()->count())->toBe(1)
        ->and(AdInsight::query()->first()?->leads)->toBe(2);
});

it('fragt ein nachlaufendes Fenster ab, nicht nur den Vortag', function (): void {
    $aufbau = new Werbeaufbau;

    insightsAntwort([]);
    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    Http::assertSent(function ($anfrage): bool {
        $bereich = json_decode((string) ($anfrage->data()['time_range'] ?? '{}'), true);

        return is_array($bereich)
            && $bereich['since'] === '2026-09-11'
            && $bereich['until'] === '2026-09-17'
            // Ohne time_increment liefert Meta eine Summe, die sich nicht
            // mehr auf Tage verteilen laesst.
            && (int) ($anfrage->data()['time_increment'] ?? 0) === 1;
    });
});

it('liest Ausgaben als Dezimalzeichenkette und legt sie als Ganzzahl ab', function (): void {
    // Budgets kommen in kleinster Einheit ("2500"), Ausgaben als Dezimalzahl
    // ("25.43") -- zwei Konventionen in einer API.
    $aufbau = new Werbeaufbau;

    insightsAntwort([
        metazeile('campaign_id', 'c1', '2026-09-16', ['spend' => '25.43']),
        metazeile('campaign_id', 'c2', '2026-09-16', ['spend' => '0.07']),
    ]);

    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    expect(AdInsight::query()->where('external_id', 'c1')->first()?->spend_minor)->toBe(2543)
        // Rundungsprobe: (int) (0.07 * 100) waere 6.
        ->and(AdInsight::query()->where('external_id', 'c2')->first()?->spend_minor)->toBe(7);
});

it('legt fuer einen Tag ohne Ausgaben eine Zeile mit Null an', function (): void {
    $aufbau = new Werbeaufbau;

    insightsAntwort([metazeile('campaign_id', 'c1', '2026-09-16', [
        'spend' => '0',
        'impressions' => '0',
        'clicks' => '0',
        'inline_link_clicks' => '0',
        'actions' => [],
    ])]);

    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    $zeile = AdInsight::query()->first();

    expect($zeile)->not->toBeNull()
        ->and($zeile?->spend_minor)->toBe(0)
        ->and($zeile?->impressions)->toBe(0);
});

it('wiederholt ein Rate-Limit beim Kennzahlenabgleich', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake(['graph.test/*' => Http::response(Werbeaufbau::fehler(17), 429)]);

    try {
        app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);
        $this->fail('Der Abgleich haette werfen muessen.');
    } catch (Werbefehler $fehler) {
        expect($fehler->einordnung->kurzgrund)->toBe('rate_limit')
            ->and($fehler->einordnung->wiederholen)->toBeTrue();
    }
});

it('wiederholt ein totes Token beim Kennzahlenabgleich nicht', function (): void {
    $aufbau = new Werbeaufbau;

    Http::fake(['graph.test/*' => Http::response(Werbeaufbau::fehler(190), 401)]);

    try {
        app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);
        $this->fail('Der Abgleich haette werfen muessen.');
    } catch (Werbefehler $fehler) {
        expect($fehler->einordnung->wiederholen)->toBeFalse()
            ->and($fehler->einordnung->zustand)->toBe(ConnectionStatus::Expired);
    }
});

it('holt die Zahlen nie im Anfragezyklus', function (): void {
    $aufbau = new Werbeaufbau;

    Queue::fake();
    Http::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.abgleichen', ['werbekonto' => $aufbau->konto->uuid]))
        ->assertRedirect();

    Queue::assertPushed(WerbezahlenAbgleichen::class);
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Rechnen
|--------------------------------------------------------------------------
*/

it('summiert einen Zeitraum aus den Tageszeilen', function (): void {
    $aufbau = new Werbeaufbau;

    insightsAntwort([
        metazeile('campaign_id', 'c1', '2026-09-15', ['spend' => '10.00', 'impressions' => '1000', 'clicks' => '10']),
        metazeile('campaign_id', 'c1', '2026-09-16', ['spend' => '20.00', 'impressions' => '3000', 'clicks' => '20']),
    ]);

    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    $summe = app(Kennzahlen::class)->gesamt(
        CarbonImmutable::parse('2026-09-11'),
        CarbonImmutable::parse('2026-09-17'),
    );

    expect($summe->ausgabenMinor)->toBe(3000)
        ->and($summe->impressionen)->toBe(4000)
        ->and($summe->klicks)->toBe(30)
        ->and($summe->tage)->toBe(2)
        // 6 Leads: zwei Zeilen mit je 3, page_engagement zaehlt nicht mit.
        ->and($summe->leads)->toBe(6);
});

it('rechnet CTR, CPC und CPM, statt sie zu speichern', function (): void {
    $aufbau = new Werbeaufbau;

    insightsAntwort([
        metazeile('campaign_id', 'c1', '2026-09-16', ['spend' => '30.00', 'impressions' => '10000', 'clicks' => '200']),
    ]);

    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    $summe = app(Kennzahlen::class)->gesamt(
        CarbonImmutable::parse('2026-09-11'),
        CarbonImmutable::parse('2026-09-17'),
    );

    expect($summe->ctr())->toBe(2.0)
        ->and($summe->cpc())->toBe(15.0)
        ->and($summe->cpm())->toBe(300.0)
        // Keine Spalte dafuer: die zweite Zahl fuer dieselbe Aussage waere
        // die, die irgendwann abweicht.
        ->and(array_keys(AdInsight::query()->first()?->getAttributes() ?? []))
        ->not->toContain('ctr');
});

it('liefert bei fehlendem Nenner einen Strich statt einer Division durch Null', function (): void {
    $leer = new Kennzahlensatz;

    expect($leer->ctr())->toBeNull()
        ->and($leer->cpc())->toBeNull()
        ->and($leer->cpm())->toBeNull()
        // Null Ergebnisse heisst nicht "kostenlos", sondern "keine Aussage".
        ->and($leer->kostenJeErgebnis())->toBeNull();
});

it('bringt die Summe je Kampagne mit der Gesamtsumme zur Deckung', function (): void {
    $aufbau = new Werbeaufbau;

    insightsAntwort([
        metazeile('campaign_id', 'c1', '2026-09-15', ['spend' => '10.00']),
        metazeile('campaign_id', 'c2', '2026-09-15', ['spend' => '15.50']),
        metazeile('campaign_id', 'c2', '2026-09-16', ['spend' => '4.50']),
    ]);

    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    $von = CarbonImmutable::parse('2026-09-11');
    $bis = CarbonImmutable::parse('2026-09-17');

    $jeKampagne = app(Kennzahlen::class)->jeKampagne($von, $bis);
    $gesamt = app(Kennzahlen::class)->gesamt($von, $bis);

    $summe = array_sum(array_map(fn ($satz): int => $satz->ausgabenMinor, $jeKampagne));

    expect($jeKampagne)->toHaveCount(2)
        ->and($jeKampagne['c1']->ausgabenMinor)->toBe(1000)
        ->and($jeKampagne['c2']->ausgabenMinor)->toBe(2000)
        ->and($summe)->toBe($gesamt->ausgabenMinor);
});

/*
|--------------------------------------------------------------------------
| Regeln
|--------------------------------------------------------------------------
*/

it('zeigt keiner Praxis die Zahlen einer anderen', function (): void {
    $eine = new Werbeaufbau(organisation('Praxis A'));

    insightsAntwort([metazeile('campaign_id', 'c1', '2026-09-16')]);
    app(Kennzahlenabgleich::class)->gleicheAb($eine->konto);

    alsMandant(organisation('Praxis B'));

    expect(AdInsight::query()->count())->toBe(0)
        ->and(app(Kennzahlen::class)->gesamt(
            CarbonImmutable::parse('2026-09-11'),
            CarbonImmutable::parse('2026-09-17'),
        )->ausgabenMinor)->toBe(0);
});

it('laedt die Seite bei einer Stoerung mit dem letzten Stand', function (): void {
    $aufbau = new Werbeaufbau;

    insightsAntwort([metazeile('campaign_id', 'c1', '2026-09-16', ['spend' => '12.00'])]);
    app(Kennzahlenabgleich::class)->gleicheAb($aufbau->konto);

    $aufbau->konto->meldeAusfall(ConnectionStatus::Expired, 'token_invalid');

    Http::fake(['graph.test/*' => Http::response([], 500)]);

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->get(route('werbung.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->where('konto.zustand', 'expired')
            ->where('summe.ausgaben', 1200)
        );
});

it('laesst nach zwoelf Monaten nur die Kampagnenebene stehen', function (): void {
    // Entscheidung P9. Laeuft ueber die Aufbewahrung aus WP-18 -- mit
    // Vorschau, wie alles dort.
    $aufbau = new Werbeaufbau;

    foreach ([InsightLevel::Campaign, InsightLevel::AdSet, InsightLevel::Ad] as $ebene) {
        foreach (['2024-09-16', '2026-09-16'] as $tag) {
            AdInsight::query()->create([
                'ad_account_id' => $aufbau->konto->getKey(),
                'level' => $ebene->value,
                'external_id' => $ebene->value.'-1',
                'stat_date' => $tag,
                'spend_minor' => 1000,
            ]);
        }
    }

    $aufbewahrung = app(Aufbewahrung::class);
    $aufbewahrung->richteEin();

    $vorschau = $aufbewahrung->lauf(vorschau: true);

    expect($vorschau->nachGegenstand()[RetentionSubject::AdInsightDetail->value] ?? 0)->toBe(2)
        ->and(AdInsight::query()->count())->toBe(6);

    $aufbewahrung->lauf(vorschau: false);

    expect(AdInsight::query()->count())->toBe(4)
        // Die alte Kampagnenzeile bleibt -- sonst waere jede
        // Jahresauswertung leer.
        ->and(AdInsight::query()->ebene(InsightLevel::Campaign)->count())->toBe(2)
        ->and(AdInsight::query()->ebene(InsightLevel::Ad)->count())->toBe(1);
});

it('zeigt eine Kampagne ohne Auslieferung anders als eine mit Nullen', function (): void {
    $aufbau = new Werbeaufbau;

    foreach (['c1', 'c2'] as $kennung) {
        AdCampaign::query()->create([
            'ad_account_id' => $aufbau->konto->getKey(),
            'external_id' => $kennung,
            'name' => 'Kampagne '.$kennung,
            'status' => 'ACTIVE',
        ]);
    }

    AdInsight::query()->create([
        'ad_account_id' => $aufbau->konto->getKey(),
        'level' => InsightLevel::Campaign->value,
        'external_id' => 'c1',
        'stat_date' => '2026-09-16',
        'spend_minor' => 0,
        'impressions' => 0,
    ]);

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->get(route('werbung.index'))
        ->assertInertia(function ($seite): void {
            /** @var list<array<string, mixed>> $zeilen */
            $zeilen = $seite->toArray()['props']['kampagnen'];

            $nach = [];

            foreach ($zeilen as $zeile) {
                $nach[(string) $zeile['kennung']] = $zeile;
            }

            // Gelaufen und nichts erreicht ...
            expect($nach['c1']['zahlen'])->not->toBeNull()
                ->and($nach['c1']['zahlen']['impressionen'])->toBe(0)
                // ... ist etwas anderes als gar nicht gelaufen.
                ->and($nach['c2']['zahlen'])->toBeNull();
        });
});
