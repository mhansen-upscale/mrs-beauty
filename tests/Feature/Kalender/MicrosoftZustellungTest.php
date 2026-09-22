<?php

declare(strict_types=1);

use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarProvider;
use App\Enums\Role;
use App\Jobs\KalenderAboErneuern;
use App\Jobs\KalenderRueckabgleich;
use App\Kalender\Abonnements;
use App\Kalender\Rueckabgleich;
use App\Models\AppointmentSlot;
use App\Models\CalendarConnection;
use App\Models\ExternalCalendarBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kalender\Googleattrappe;
use Tests\Feature\Kalender\Graphattrappe;
use Tests\Feature\Kalender\Graphaufbau;
use Tests\Feature\Kalender\Kalenderaufbau;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-15, Abnahmekriterien 1 bis 3 und 17 bis 23
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 06:00:00', 'UTC'));
});

/**
 * Eine Zustellung, wie Graph sie schickt: alles im Rumpf, nichts in Koepfen.
 *
 * @return array<string, mixed>
 */
function graphZustellung(Graphaufbau $aufbau, string $ressource = 'ereignis-1', ?string $geheimnis = null): array
{
    return [
        'value' => [[
            'subscriptionId' => (string) $aufbau->verbindung->channel_id,
            'clientState' => $geheimnis ?? (string) $aufbau->verbindung->channel_token,
            'changeType' => 'updated',
            'resource' => 'Users/x/Events/'.$ressource,
            'resourceData' => ['id' => $ressource],
        ]],
    ];
}

it('antwortet auf den Handschlag mit dem Merkmal als reinem Text', function (): void {
    // Ohne diese Antwort binnen Sekunden entsteht das Abonnement gar nicht.
    $antwort = post(route('kalender.microsoft.webhook').'?validationToken=merkmal-123');

    $antwort->assertOk()
        ->assertHeader('content-type', 'text/plain; charset=UTF-8');

    expect($antwort->getContent())->toBe('merkmal-123');
});

it('quittiert sofort und verarbeitet asynchron', function (): void {
    $aufbau = new Graphaufbau;
    ohneMandant();
    Queue::fake();

    postJson(route('kalender.microsoft.webhook'), graphZustellung($aufbau))->assertAccepted();

    Queue::assertPushed(KalenderRueckabgleich::class, 1);
});

it('loest bei falschem clientState nichts aus', function (): void {
    $aufbau = new Graphaufbau;
    ohneMandant();
    Queue::fake();

    postJson(route('kalender.microsoft.webhook'), graphZustellung($aufbau, geheimnis: 'geraten'))
        ->assertAccepted();

    Queue::assertNothingPushed();
});

it('erzeugt aus derselben Zustellung einen Lauf, nicht zwei', function (): void {
    $aufbau = new Graphaufbau;
    ohneMandant();
    Queue::fake();

    postJson(route('kalender.microsoft.webhook'), graphZustellung($aufbau))->assertAccepted();
    postJson(route('kalender.microsoft.webhook'), graphZustellung($aufbau))->assertAccepted();

    Queue::assertPushed(KalenderRueckabgleich::class, 1);
});

it('verlaengert das Abonnement, statt ein neues zu bestellen', function (): void {
    $aufbau = new Graphaufbau;
    $alteKennung = (string) $aufbau->verbindung->channel_id;

    app(Abonnements::class)->erneuere($aufbau->verbindung);

    expect($aufbau->graph->verlaengert)->toHaveCount(1)
        ->and($aufbau->graph->abonniert)->toBeEmpty()
        // Dieselbe Kennung, also auch dasselbe Geheimnis: bei Graph haengt es
        // am Abonnement und laesst sich beim Verlaengern nicht wechseln.
        ->and($aufbau->verbindung->fresh()?->channel_id)->toBe($alteKennung)
        ->and($aufbau->verbindung->fresh()?->channel_token)->toBe('geheimnis-graph');
});

it('bestellt ein neues Abonnement, wenn das alte drueben nicht mehr existiert', function (): void {
    $aufbau = new Graphaufbau;
    $aufbau->graph->abonnementFehlt = true;

    app(Abonnements::class)->erneuere($aufbau->verbindung);

    expect($aufbau->graph->abonniert)->toHaveCount(1)
        ->and($aufbau->verbindung->fresh()?->channel_id)->toBe('abo-1');
});

it('haelt fuer Microsoft einen kuerzeren Erneuerungsvorlauf als fuer Google', function (): void {
    // Ein Graph-Abonnement lebt keine drei Tage. Ein Vorlauf von 24 Stunden
    // waere fast ein Drittel davon -- erneuert wuerde bei jedem Lauf.
    $aufbau = new Graphaufbau;
    $google = Kalenderaufbau::class;

    expect($aufbau->verbindung->erneuerungsvorlauf())->toBe(6)
        ->and($aufbau->verbindung->brauchtErneuerung(CarbonImmutable::now()))->toBeFalse();

    // Dieselbe Restlaufzeit ist bei Google laengst erneuerungsreif.
    $aufbau->verbindung->channel_expires_at = CarbonImmutable::now()->addHours(20);
    $aufbau->verbindung->save();

    expect($aufbau->verbindung->brauchtErneuerung(CarbonImmutable::now()))->toBeFalse()
        ->and($google)->toBe(Kalenderaufbau::class);

    $aufbau->verbindung->channel_expires_at = CarbonImmutable::now()->addHours(4);
    $aufbau->verbindung->save();

    expect($aufbau->verbindung->brauchtErneuerung(CarbonImmutable::now()))->toBeTrue();
});

it('setzt die Verbindung bei entzogenem Zugang auf unterbrochen', function (): void {
    $aufbau = new Graphaufbau;
    $aufbau->graph->zugangEntzogen = true;

    $ergebnis = app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    expect($ergebnis->unterbrochen)->toBeTrue()
        ->and($aufbau->verbindung->fresh()?->status)->toBe(CalendarConnectionStatus::Expired);
});

it('legt aus dem Rueckweg eine Microsoft-Verbindung an', function (): void {
    $aufbau = new Graphaufbau;
    $aufbau->verbindung->delete();

    $behandler = $aufbau->szenario->aufbau->behandler;
    $benutzer = User::factory()->fuer($aufbau->organisation, Role::Owner)->create();

    $weiter = actingAs($benutzer)
        ->get(route('kalender.microsoft.verbinden', ['practitioner' => $behandler->uuid]));

    $ziel = (string) $weiter->headers->get('Location');

    expect($ziel)->toContain('login.microsoftonline.com')
        // Ohne offline_access gibt es keinen Aktualisierungsschluessel.
        ->and($ziel)->toContain('offline_access');

    parse_str((string) parse_url($ziel, PHP_URL_QUERY), $abfrage);

    actingAs($benutzer)
        ->get(route('kalender.microsoft.rueckkehr', ['code' => 'ein-code', 'state' => $abfrage['state']]))
        ->assertRedirect(route('kalender.index'));

    $verbindung = CalendarConnection::query()->firstOrFail();

    expect($verbindung->provider)->toBe(CalendarProvider::Microsoft)
        ->and($verbindung->access_token)->toBe('zugang-neu')
        ->and($verbindung->account_email)->toBe('praxis@outlook.test')
        ->and($verbindung->calendar_timezone)->toBe('Europe/Berlin');
});

it('nimmt einen Rueckweg nicht an, der bei einem anderen Anbieter losging', function (): void {
    $aufbau = new Graphaufbau;
    $aufbau->verbindung->delete();

    $benutzer = User::factory()->fuer($aufbau->organisation, Role::Owner)->create();

    $ziel = (string) actingAs($benutzer)
        ->get(route('kalender.google.verbinden', ['practitioner' => $aufbau->szenario->aufbau->behandler->uuid]))
        ->headers->get('Location');

    parse_str((string) parse_url($ziel, PHP_URL_QUERY), $abfrage);

    // Ein Google-Code am Microsoft-Endpunkt.
    actingAs($benutzer)
        ->get(route('kalender.microsoft.rueckkehr', ['code' => 'ein-code', 'state' => $abfrage['state']]))
        ->assertRedirect(route('kalender.index'));

    expect(CalendarConnection::query()->count())->toBe(0);
});

it('laesst einen Behandler beide Anbieter verbinden', function (): void {
    $aufbau = new Kalenderaufbau;
    $zweite = Graphaufbau::verbinde($aufbau->szenario->aufbau->behandler);

    expect(CalendarConnection::query()->count())->toBe(2)
        ->and($zweite->provider)->toBe(CalendarProvider::Microsoft);
});

it('erzeugt aus zwei Anbietern keine doppelten Blocker', function (): void {
    // Derselbe Termin steht in beiden Kalendern. Die Zeit ist einmal belegt,
    // nicht zweimal -- und wenn ein Anbieter abgeglichen wird, bleiben die
    // Blocker des anderen stehen.
    $aufbau = new Kalenderaufbau;
    $behandler = $aufbau->szenario->aufbau->behandler;
    $microsoft = Graphaufbau::verbinde($behandler);

    $von = CarbonImmutable::parse(Szenario::TAG.' 09:00:00', 'UTC');
    $bis = $von->addHour();

    // Google meldet die Zeit ...
    $aufbau->google->ereignisse = [
        Googleattrappe::ereignis('g-1', $von->toRfc3339String(), $bis->toRfc3339String()),
    ];
    app(Rueckabgleich::class)->fuer($aufbau->verbindung);

    $belegt = fn (): int => AppointmentSlot::query()
        ->whereNotNull('external_block_id')
        ->where('starts_at', '>=', $von)
        ->where('starts_at', '<', $bis)
        ->count();

    expect($belegt())->toBe(12);

    // ... und Microsoft dieselbe.
    $graph = new Graphattrappe;
    $graph->installiere();
    $graph->ereignisse = [
        Graphattrappe::ereignis('m-1', $von->format('Y-m-d\TH:i:s').'.0000000', $bis->format('Y-m-d\TH:i:s').'.0000000', 'UTC'),
    ];
    app(Rueckabgleich::class)->fuer($microsoft);

    expect($belegt())->toBe(12)
        ->and(ExternalCalendarBlock::query()->count())->toBe(2);
});

it('stellt fuer beide Anbieter einen Abgleich ein', function (): void {
    $aufbau = new Kalenderaufbau;
    Graphaufbau::verbinde($aufbau->szenario->aufbau->behandler);

    Queue::fake();
    expect(Artisan::call('mrs:kalender-abgleichen'))->toBe(0);

    Queue::assertPushed(KalenderRueckabgleich::class, 2);
});

it('erneuert die Abonnements beider Anbieter ueber denselben Befehl', function (): void {
    $aufbau = new Kalenderaufbau;
    $microsoft = Graphaufbau::verbinde($aufbau->szenario->aufbau->behandler);

    $aufbau->verbindung->channel_expires_at = CarbonImmutable::now()->addHours(20);
    $aufbau->verbindung->save();
    $microsoft->channel_expires_at = CarbonImmutable::now()->addHours(4);
    $microsoft->save();

    Queue::fake();
    expect(Artisan::call('mrs:kalender-abos-erneuern'))->toBe(0);

    Queue::assertPushed(KalenderAboErneuern::class, 2);
});
