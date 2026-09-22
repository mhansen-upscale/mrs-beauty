<?php

declare(strict_types=1);

use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarPrivacyMode;
use App\Enums\CalendarProvider;
use App\Enums\Role;
use App\Jobs\KalenderAboErneuern;
use App\Jobs\KalenderRueckabgleich;
use App\Kalender\Abonnements;
use App\Models\AppointmentSlot;
use App\Models\CalendarConnection;
use App\Models\ExternalCalendarBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kalender\Kalenderaufbau;

/*
|--------------------------------------------------------------------------
| WP-14, Abnahmekriterien 1 bis 4 und 30 bis 32 -- Verbindung und Ausfall
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 06:00:00', 'UTC'));
});

/** Wer Kalender verbinden darf. */
function inhaberin(Kalenderaufbau $aufbau): User
{
    return User::factory()->fuer($aufbau->organisation, Role::Owner)->create();
}

/** Der `state`, den die Weiterleitung mitgibt. */
function stateAus(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $abfrage);

    return is_string($abfrage['state'] ?? null) ? $abfrage['state'] : '';
}

it('legt aus dem Rueckweg eine Verbindung an und haelt die Token verschluesselt', function (): void {
    $aufbau = new Kalenderaufbau;
    $aufbau->verbindung->delete();

    $behandler = $aufbau->szenario->aufbau->behandler;

    $weiter = actingAs(inhaberin($aufbau))
        ->get(route('kalender.google.verbinden', ['practitioner' => $behandler->uuid]));

    $weiter->assertRedirect();

    $ziel = (string) $weiter->headers->get('Location');

    expect($ziel)->toContain('access_type=offline')
        ->and($ziel)->toContain('prompt=consent');

    actingAs(inhaberin($aufbau))
        ->get(route('kalender.google.rueckkehr', ['code' => 'ein-code', 'state' => stateAus($ziel)]))
        ->assertRedirect(route('kalender.index'));

    $verbindung = CalendarConnection::query()->firstOrFail();

    expect($verbindung->practitioner_id)->toBe($behandler->getKey())
        ->and($verbindung->status)->toBe(CalendarConnectionStatus::Active)
        ->and($verbindung->access_token)->toBe('zugang-neu')
        ->and($verbindung->refresh_token)->toBe('aktualisierung-neu')
        // Die Zone kommt vom Kalender, sie wird nicht angenommen.
        ->and($verbindung->calendar_timezone)->toBe('Europe/Berlin')
        // Und das Abonnement steht.
        ->and($aufbau->google->abonniert)->toHaveCount(1);

    // Regel 3: in der Datenbank steht kein Klartext.
    /** @var object{access_token: string, refresh_token: string} $roh */
    $roh = DB::table('calendar_connections')->first();

    expect($roh->access_token)->not->toContain('zugang-neu')
        ->and($roh->refresh_token)->not->toContain('aktualisierung-neu');
});

it('verbindet bei manipuliertem state nichts', function (): void {
    $aufbau = new Kalenderaufbau;
    $aufbau->verbindung->delete();

    actingAs(inhaberin($aufbau))
        ->get(route('kalender.google.rueckkehr', ['code' => 'ein-code', 'state' => 'selbst-ausgedacht']))
        ->assertRedirect(route('kalender.index'));

    expect(CalendarConnection::query()->count())->toBe(0);
});

it('laesst je Behandler und Anbieter nur eine Verbindung zu', function (): void {
    $aufbau = new Kalenderaufbau;

    $zweite = new CalendarConnection;
    $zweite->practitioner_id = $aufbau->szenario->aufbau->behandler->getKey();
    $zweite->provider = CalendarProvider::Google;
    $zweite->calendar_id = 'zweiter@example.com';

    expect(fn () => $zweite->save())->toThrow(QueryException::class);
});

it('gibt beim Trennen die Zeit wieder frei', function (): void {
    $aufbau = new Kalenderaufbau;

    $block = new ExternalCalendarBlock;
    $block->calendar_connection_id = $aufbau->verbindung->getKey();
    $block->practitioner_id = $aufbau->szenario->aufbau->behandler->getKey();
    $block->external_id = 'extern-1';
    $block->starts_at = CarbonImmutable::parse('2027-01-13 09:00:00', 'UTC');
    $block->ends_at = CarbonImmutable::parse('2027-01-13 10:00:00', 'UTC');
    $block->save();

    AppointmentSlot::query()
        ->where('starts_at', '>=', $block->starts_at)
        ->where('starts_at', '<', $block->ends_at)
        ->update(['external_block_id' => $block->getKey()]);

    expect(AppointmentSlot::query()->whereNotNull('external_block_id')->count())->toBe(12);

    actingAs(inhaberin($aufbau))
        ->delete(route('kalender.trennen', ['verbindung' => $aufbau->verbindung->uuid]))
        ->assertRedirect(route('kalender.index'));

    expect(CalendarConnection::query()->count())->toBe(0)
        ->and(ExternalCalendarBlock::query()->count())->toBe(0)
        ->and(AppointmentSlot::query()->whereNotNull('external_block_id')->count())->toBe(0)
        // Ein Kanal ohne Gegenstelle stellt weiter ins Leere zu.
        ->and($aufbau->google->beendet)->toHaveCount(1);
});

it('erneuert ein Abonnement deutlich vor Ablauf', function (): void {
    $aufbau = new Kalenderaufbau;

    // Innerhalb des Vorlaufs aus config/mrs.php, aber noch lange gueltig.
    $aufbau->verbindung->channel_expires_at = CarbonImmutable::now()->addHours(20);
    $aufbau->verbindung->save();

    $alterKanal = (string) $aufbau->verbindung->channel_id;

    Queue::fake();
    expect(Artisan::call('mrs:kalender-abos-erneuern'))->toBe(0);
    Queue::assertPushed(KalenderAboErneuern::class, 1);

    app(KalenderAboErneuern::class, [
        'verbindung' => (string) $aufbau->verbindung->uuid,
        'organisation' => (string) $aufbau->organisation->uuid,
    ])->handle(app(Abonnements::class));

    $frisch = $aufbau->verbindung->fresh();

    expect($aufbau->google->abonniert)->toHaveCount(1)
        ->and($frisch?->channel_id)->not->toBe($alterKanal)
        ->and($frisch?->channel_expires_at?->greaterThan(CarbonImmutable::now()->addDays(20)))->toBeTrue()
        // Erst der neue Kanal, dann der alte beendet -- sonst entstuende eine
        // Luecke, in der Aenderungen nicht zugestellt werden.
        ->and($aufbau->google->beendet)->toHaveCount(1)
        ->and($aufbau->google->beendet[0]['id'])->toBe($alterKanal);
});

it('erneuert ein Abonnement nicht, solange es lange gilt', function (): void {
    new Kalenderaufbau;

    Queue::fake();
    expect(Artisan::call('mrs:kalender-abos-erneuern'))->toBe(0);

    Queue::assertNothingPushed();
});

it('setzt die Verbindung bei entzogenem Zugang auf unterbrochen', function (): void {
    $aufbau = new Kalenderaufbau;
    $aufbau->google->zugangEntzogen = true;

    app(KalenderAboErneuern::class, [
        'verbindung' => (string) $aufbau->verbindung->uuid,
        'organisation' => (string) $aufbau->organisation->uuid,
    ])->handle(app(Abonnements::class));

    $frisch = $aufbau->verbindung->fresh();

    expect($frisch?->status)->toBe(CalendarConnectionStatus::Expired)
        ->and($frisch?->last_error)->toBe('access_revoked')
        ->and($frisch?->failed_at)->not->toBeNull();
});

it('macht den Ausfall im Produkt sichtbar', function (): void {
    // R4: ein Ausfall erzeugt einen Hinweis im Produkt, nicht nur im Log.
    $aufbau = new Kalenderaufbau;
    $aufbau->verbindung->meldeAusfall('access_revoked');

    actingAs(inhaberin($aufbau))
        ->get(route('kalender.index'))
        ->assertInertia(fn ($seite) => $seite
            ->component('kalender/Verbindungen')
            ->where('practitioners.0.connections.0.needs_attention', true)
            ->where('practitioners.0.connections.0.status_label', 'Unterbrochen')
            // Und in **jeder** Antwort, nicht nur auf dieser Seite.
            ->where('calendar_alert', true)
        );
});

it('zeigt ohne Ausfall keine Warnung', function (): void {
    $aufbau = new Kalenderaufbau;

    actingAs(inhaberin($aufbau))
        ->get(route('kalender.index'))
        ->assertInertia(fn ($seite) => $seite
            ->where('practitioners.0.connections.0.needs_attention', false)
            ->where('calendar_alert', false)
        );
});

it('laesst ohne masterdata.manage niemanden an die Kalender', function (): void {
    $aufbau = new Kalenderaufbau;

    $empfang = User::factory()->fuer($aufbau->organisation, Role::Reception)->create();

    actingAs($empfang)->get(route('kalender.index'))->assertForbidden();
});

it('stellt fuer jede aktive Verbindung einen Abgleich ein', function (): void {
    $aufbau = new Kalenderaufbau;

    Queue::fake();
    expect(Artisan::call('mrs:kalender-abgleichen'))->toBe(0);
    Queue::assertPushed(KalenderRueckabgleich::class, 1);

    // Eine unterbrochene Verbindung wird nicht abgeglichen -- sie muss erst
    // neu hergestellt werden.
    $aufbau->verbindung->meldeAusfall('access_revoked');

    Queue::fake();
    expect(Artisan::call('mrs:kalender-abgleichen'))->toBe(0);
    Queue::assertNothingPushed();
});

it('verlaesst beim Abgleich nie die Organisation einer Verbindung', function (): void {
    $ersterAufbau = new Kalenderaufbau;
    $erste = $ersterAufbau->organisation;

    // Eine zweite Praxis, ebenfalls mit Kalender.
    $zweiterAufbau = new Kalenderaufbau(organisation('Zweite Praxis'));
    $zweite = $zweiterAufbau->organisation;

    Queue::fake();
    expect(Artisan::call('mrs:kalender-abgleichen'))->toBe(0);

    /** @var list<array{0: string, 1: string}> $nutzlasten */
    $nutzlasten = [];

    Queue::assertPushed(KalenderRueckabgleich::class, function (KalenderRueckabgleich $auftrag) use (&$nutzlasten): bool {
        /** @var array{0: string, 1: string} $paar */
        $paar = (fn (): array => [$this->verbindung, $this->organisation])->call($auftrag);
        $nutzlasten[] = $paar;

        return true;
    });

    // Jede Verbindung geht mit **ihrer** Organisation los, nicht mit der, die
    // beim Aufruf gerade galt.
    expect($nutzlasten)->toHaveCount(2)
        ->and($nutzlasten)->toContain([(string) $ersterAufbau->verbindung->uuid, (string) $erste->uuid])
        ->and($nutzlasten)->toContain([(string) $zweiterAufbau->verbindung->uuid, (string) $zweite->uuid]);
});

it('aendert die Sichtbarkeit einer Verbindung', function (): void {
    $aufbau = new Kalenderaufbau;

    actingAs(inhaberin($aufbau))
        ->patch(route('kalender.aktualisieren', ['verbindung' => $aufbau->verbindung->uuid]), [
            'privacy_mode' => 'details',
        ])
        ->assertRedirect();

    expect($aufbau->verbindung->fresh()?->privacy_mode)->toBe(CalendarPrivacyMode::Details);
});

it('nimmt keinen erfundenen Sichtbarkeitsmodus an', function (): void {
    $aufbau = new Kalenderaufbau;

    actingAs(inhaberin($aufbau))
        ->patch(route('kalender.aktualisieren', ['verbindung' => $aufbau->verbindung->uuid]), [
            'privacy_mode' => 'alles_zeigen',
        ])
        ->assertSessionHasErrors('privacy_mode');
});
