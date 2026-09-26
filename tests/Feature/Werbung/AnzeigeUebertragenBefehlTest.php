<?php

declare(strict_types=1);

use App\Enums\SyncState;
use App\Jobs\AnzeigeUebertragen;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\travelTo;

use Tests\Feature\Werbung\Anzeigenaufbau;
use Tests\Feature\Werbung\Werbeaufbau;

/*
|--------------------------------------------------------------------------
| mrs:anzeige-uebertragen -- eine haengende Anzeige von Hand
|--------------------------------------------------------------------------
|
| Am 26.09.2026 stand eine Anzeige auf Staging eine Viertelstunde auf "wird
| uebertragen". Der Auftrag hatte weder einen Fehler vermerkt noch
| aufgegeben -- er war nie angelaufen. Die Oberflaeche bot in diesem Zustand
| keinen Knopf, und die Laravel-Cloud-Konsole nimmt nur einzelne Kommandos,
| kein mehrzeiliges tinker. Es gab keinen Weg, den Grund zu lesen, und
| keinen, die Anzeige anzustossen.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-26 09:00:00', 'UTC'));
    config()->set('mrs.meta.graph_url', 'https://graph.test');
    config()->set('mrs.meta.api_version', 'v21.0');
});

/** Meta nimmt Bild, Creative und Anzeige an. */
function metaNimmtAn(): void
{
    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
        'graph.test/*/act_*/ads' => Http::response(['id' => 'anzeige-1']),
    ]);
}

it('zeigt mit --nur-zeigen den Zustand und schickt nichts', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    ohneMandant();

    Http::fake();

    expect(Artisan::call('mrs:anzeige-uebertragen', [
        'anzeige' => (string) $anzeige->uuid,
        '--nur-zeigen' => true,
    ]))->toBe(0);

    Http::assertNothingSent();

    expect(Artisan::output())->toContain('pending')
        ->and($anzeige->fresh()?->sync_state)->toBe(SyncState::Pending);
});

it('zeigt den gescheiterten Auftrag aus failed_jobs', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    ohneMandant();

    // Der Grund, an dem der Worker aufgab. Ohne ihn bleibt nur das
    // Protokoll -- und das ist in der Cloud-Konsole nicht zur Hand.
    app(FailedJobProviderInterface::class)->log(
        'cloud',
        'default',
        (string) json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => AnzeigeUebertragen::class,
            'data' => ['command' => 'anzeige:'.$anzeige->uuid],
        ]),
        new RuntimeException('Worker mitten im Upload beendet'),
    );

    Http::fake();

    Artisan::call('mrs:anzeige-uebertragen', [
        'anzeige' => (string) $anzeige->uuid,
        '--nur-zeigen' => true,
    ]);

    expect(Artisan::output())->toContain('Worker mitten im Upload beendet');
});

it('uebertraegt die Anzeige und meldet Erfolg', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    ohneMandant();

    metaNimmtAn();

    expect(Artisan::call('mrs:anzeige-uebertragen', ['anzeige' => (string) $anzeige->uuid]))->toBe(0);

    $frisch = $anzeige->fresh();

    expect($frisch?->sync_state)->toBe(SyncState::Synced)
        ->and($frisch?->external_id)->toBe('anzeige-1');
});

it('meldet eine Ablehnung von Meta mit Grund und Fehlercode', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    ohneMandant();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*' => Http::response([
            'error' => ['message' => 'Das Bild ist zu klein für dieses Format.', 'code' => 100],
        ], 400),
    ]);

    expect(Artisan::call('mrs:anzeige-uebertragen', ['anzeige' => (string) $anzeige->uuid]))->toBe(1);

    $frisch = $anzeige->fresh();

    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        ->and(Artisan::output())->toContain((string) $frisch?->sync_error);
});

it('uebertraegt ueber das Werbekonto der eigenen Praxis, nie ueber ein fremdes', function (): void {
    // **Die fremde Praxis zuerst.** Liefe der Auftrag mit ausgesetztem
    // Scope, faende `AdAccount::first()` genau dieses Konto (Regel 1).
    $fremd = new Werbeaufbau(organisation('Andere Praxis'));
    $fremd->konto->external_id = 'act_fremd';
    $fremd->konto->save();

    $aufbau = new Anzeigenaufbau(organisation('Eigene Praxis'));
    $anzeige = $aufbau->geplanteAnzeige();
    ohneMandant();

    metaNimmtAn();

    Artisan::call('mrs:anzeige-uebertragen', ['anzeige' => (string) $anzeige->uuid]);

    Http::assertNotSent(fn ($anfrage): bool => str_contains((string) $anfrage->url(), 'act_fremd'));
    Http::assertSent(fn ($anfrage): bool => str_contains((string) $anfrage->url(), Werbeaufbau::KONTO));
});

it('meldet eine unbekannte Anzeige und schickt nichts', function (): void {
    new Anzeigenaufbau;
    ohneMandant();

    Http::fake();

    expect(Artisan::call('mrs:anzeige-uebertragen', ['anzeige' => (string) Str::uuid()]))->toBe(1);

    Http::assertNothingSent();
});

it('uebertraegt trotz liegengebliebener Sperre und gibt sie frei', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    ohneMandant();

    // Ein Auftrag, der verlorenging, laesst seine Sperre zurueck -- ohne
    // `uniqueFor` fuer immer. Jeder weitere Dispatch wird dann verworfen.
    $sperre = UniqueLock::getKey(new AnzeigeUebertragen(
        (string) $aufbau->werbung->organisation->uuid,
        (string) $anzeige->uuid,
    ));
    Cache::lock($sperre)->get();

    metaNimmtAn();

    expect(Artisan::call('mrs:anzeige-uebertragen', ['anzeige' => (string) $anzeige->uuid]))->toBe(0)
        ->and($anzeige->fresh()?->sync_state)->toBe(SyncState::Synced)
        ->and(Cache::lock($sperre)->get())->toBeTrue();
});

it('laesst eine bereits uebertragene Anzeige in Ruhe', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    $anzeige->external_id = 'anzeige-1';
    $anzeige->sync_state = SyncState::Synced;
    $anzeige->save();
    ohneMandant();

    Http::fake();

    expect(Artisan::call('mrs:anzeige-uebertragen', ['anzeige' => (string) $anzeige->uuid]))->toBe(0);

    Http::assertNothingSent();
});
