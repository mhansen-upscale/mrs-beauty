<?php

declare(strict_types=1);

use App\Jobs\KalenderRueckabgleich;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kalender\Kalenderaufbau;

/*
|--------------------------------------------------------------------------
| WP-14, Abnahmekriterien 21 bis 25 -- die Zustellung
|--------------------------------------------------------------------------
|
| Entscheidung A14: Signatur pruefen, sofort quittieren, asynchron
| verarbeiten, ueber die externe ID deduplizieren.
|
| Die Zustellung kommt ohne Anmeldung an. Kein Test hier meldet jemanden an --
| das ist nicht Nachlaessigkeit, sondern der Fall.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 06:00:00', 'UTC'));
});

/** Eine verbundene Praxis -- und danach kein geltender Mandant mehr. */
function zustellbereit(): Kalenderaufbau
{
    $aufbau = new Kalenderaufbau;

    // Der Mandant gilt ab hier nicht mehr: die Zustellung traegt nur die
    // Kanalkennung.
    ohneMandant();

    Queue::fake();

    return $aufbau;
}

/**
 * @return array<string, string>
 */
function zustellung(Kalenderaufbau $aufbau, string $zustand = 'exists', string $nummer = '1', ?string $token = null): array
{
    return [
        'X-Goog-Channel-ID' => (string) $aufbau->verbindung->channel_id,
        'X-Goog-Channel-Token' => $token ?? (string) $aufbau->verbindung->channel_token,
        'X-Goog-Resource-State' => $zustand,
        'X-Goog-Message-Number' => $nummer,
    ];
}

it('quittiert sofort und verarbeitet asynchron', function (): void {
    $aufbau = zustellbereit();

    $antwort = postJson(route('kalender.google.webhook'), [], zustellung($aufbau));

    $antwort->assertNoContent();

    Queue::assertPushed(KalenderRueckabgleich::class, 1);
});

it('arbeitet im Mandanten der Verbindung, obwohl die Zustellung keinen kennt', function (): void {
    $aufbau = zustellbereit();

    postJson(route('kalender.google.webhook'), [], zustellung($aufbau))->assertNoContent();

    Queue::assertPushed(KalenderRueckabgleich::class, function (KalenderRueckabgleich $auftrag) use ($aufbau): bool {
        /** @var array{verbindung: string, organisation: string} $nutzlast */
        $nutzlast = (fn (): array => [
            'verbindung' => $this->verbindung,
            'organisation' => $this->organisation,
        ])->call($auftrag);

        return $nutzlast['verbindung'] === (string) $aufbau->verbindung->uuid
            && $nutzlast['organisation'] === (string) $aufbau->organisation->uuid;
    });
});

it('loest bei falschem Kanal-Token nichts aus', function (): void {
    $aufbau = zustellbereit();

    $antwort = postJson(route('kalender.google.webhook'), [], zustellung($aufbau, token: 'geraten'));

    // Quittiert wird trotzdem: ein Fehlercode brächte Google dazu, es erneut
    // zu versuchen.
    $antwort->assertNoContent();

    Queue::assertNothingPushed();
});

it('loest bei unbekanntem Kanal nichts aus', function (): void {
    $aufbau = zustellbereit();

    $kopf = zustellung($aufbau);
    $kopf['X-Goog-Channel-ID'] = (string) Str::uuid();

    postJson(route('kalender.google.webhook'), [], $kopf)->assertNoContent();

    Queue::assertNothingPushed();
});

it('erzeugt aus derselben Nachricht einen Lauf, nicht zwei', function (): void {
    $aufbau = zustellbereit();

    $kanal = (string) $aufbau->verbindung->channel_id;

    postJson(route('kalender.google.webhook'), [], zustellung($aufbau, nummer: '17'))->assertNoContent();
    postJson(route('kalender.google.webhook'), [], zustellung($aufbau, nummer: '17'))->assertNoContent();

    Queue::assertPushed(KalenderRueckabgleich::class, 1);

    // Der Schutz sitzt vor der Queue: die zweite Zustellung wird verworfen,
    // ohne dass ein Auftrag entsteht.
    expect(Cache::has("kalender:zustellung:{$kanal}:17"))->toBeTrue();
});

it('legt auch zwei verschiedene Zustellungen zu einem Lauf zusammen', function (): void {
    $aufbau = zustellbereit();

    // Zwei Nachrichten sind zwei Nachrichten -- die Deduplizierung greift
    // hier nicht. Der Auftrag selbst ist eindeutig (ShouldBeUnique), und das
    // ist die zweite Linie: eine Praxis, die zwanzig Termine hintereinander
    // verschiebt, erzeugt zwanzig Zustellungen. Der zweite Lauf haette
    // nichts zu tun, was der erste nicht schon getan haette.
    postJson(route('kalender.google.webhook'), [], zustellung($aufbau, nummer: '17'))->assertNoContent();
    postJson(route('kalender.google.webhook'), [], zustellung($aufbau, nummer: '18'))->assertNoContent();

    Queue::assertPushed(KalenderRueckabgleich::class, 1);
});

it('loest beim Zustand sync nichts aus', function (): void {
    $aufbau = zustellbereit();

    // Die erste Zustellung bestaetigt nur, dass der Kanal steht.
    postJson(route('kalender.google.webhook'), [], zustellung($aufbau, zustand: 'sync'))->assertNoContent();

    Queue::assertNothingPushed();
});

it('kommt ohne Sitzungsmerkmal aus', function (): void {
    $aufbau = zustellbereit();

    // Ohne die Ausnahme in bootstrap/app.php antwortete der CSRF-Schutz mit
    // 419, und Google versuchte es endlos erneut.
    post(route('kalender.google.webhook'), [], zustellung($aufbau))->assertNoContent();

    Queue::assertPushed(KalenderRueckabgleich::class, 1);
});
