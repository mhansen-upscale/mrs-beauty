<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Enums\SyncState;
use App\Enums\Vorschlagsstatus;
use App\Jobs\AnzeigeUebertragen;
use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Fehlereinordnung;
use App\Tenancy\TenantContext;
use App\Werbung\Verwaltung\Anzeigenschaltung;
use App\Werbung\Verwaltung\Kampagnenname;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use RuntimeException;
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

/*
|--------------------------------------------------------------------------
| Eine gescheiterte Anzeige bleibt sonst fuer immer liegen
|--------------------------------------------------------------------------
|
| Fuer Kampagnen zieht das Verbinden Liegengebliebenes nach. Fuer Anzeigen
| gab es das nicht: eine einmal gescheiterte Anzeige stand dauerhaft auf
| `failed` -- erneut schalten lehnte der Controller ab ("laeuft in dieser
| Kampagne bereits"), und nichts stellte den Auftrag neu ein.
|
| Am 23.09.2026 lag die Ursache ausserhalb des Produkts (App im
| Entwicklungsmodus). Nach dem Beheben blieb die Anzeige trotzdem liegen.
|
| **Wartend zieht das System selbst nach, abgelehnt entscheidet ein Mensch.**
| Eine fachliche Ablehnung blind zu wiederholen ist genau das, was die
| Wiederholungslogik sonst vermeidet.
|
*/

/*
|--------------------------------------------------------------------------
| Wartend ist nicht abgelehnt
|--------------------------------------------------------------------------
|
| `vermerkeFehler` unterschied nur danach, ob ein Fehler den *Verbindungs*-
| zustand setzt. Alles andere galt als abgelehnt -- auch `adset_not_synced`,
| `rate_limit`, `temporary` und `unreachable`, die ausdruecklich als
| wiederholbar eingeordnet sind.
|
| Zwei Folgen, beide am 23.09.2026 aufgefallen: Die Oberflaeche meldete rot
| "Diese Anzeige ist nicht bei Meta angekommen", obwohl die Anzeige nur auf
| ihre Kampagne wartete. Und der Wiederanlauf beim Verbinden sucht `Pending`
| -- er zog genau die Faelle **nicht** nach, die dafuer gedacht waren.
|
*/

it('haelt eine wartende Anzeige als wartend fest, nicht als abgelehnt', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Die Anzeigengruppe steht noch nicht bei Meta -- wiederholbar.
    $aufbau->gruppe->external_id = 'lokal-abcdefghij';
    $aufbau->gruppe->save();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
    ]);

    // Der Auftrag wirft bei einem wiederholbaren Fehler erneut, damit die
    // Warteschlange es noch einmal versucht. Der Vermerk steht trotzdem.
    try {
        app(AnzeigeUebertragen::class, [
            'organisation' => (string) $aufbau->werbung->organisation->uuid,
            'anzeige' => (string) $anzeige->uuid,
        ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));
    } catch (Werbefehler $erwartet) {
        expect($erwartet->einordnung->kurzgrund)->toBe('adset_not_synced');
    }

    $frisch = $anzeige->fresh();

    // Wartend -- sonst zieht der Wiederanlauf sie nie nach.
    expect($frisch?->sync_state)->toBe(SyncState::Pending)
        // Der Grund bleibt trotzdem sichtbar: er sagt, worauf gewartet wird.
        ->and($frisch?->sync_error)->toContain('Kampagne');
});

/*
|--------------------------------------------------------------------------
| Die Meldung nennt, was wirklich fehlt
|--------------------------------------------------------------------------
|
| "Die Kampagne steht noch nicht bei Meta" stand an einer Pruefung, die die
| **Anzeigengruppe** prueft. Am 24.09.2026 hat das eine Stunde gekostet: Die
| Kampagne war sichtbar im Werbekonto, die Meldung behauptete das Gegenteil,
| und niemand kam auf die Gruppe.
|
| Dazu der Name der Kampagne -- bei drei Kampagnen sagt "die Kampagne" nicht,
| welche gemeint ist.
|
*/

it('nennt die Anzeigengruppe beim Namen, nicht die Kampagne', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    $aufbau->gruppe->external_id = 'lokal-abcdefghij';
    $aufbau->gruppe->save();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
    ]);

    try {
        app(AnzeigeUebertragen::class, [
            'organisation' => (string) $aufbau->werbung->organisation->uuid,
            'anzeige' => (string) $anzeige->uuid,
        ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));
    } catch (Werbefehler) {
        // Wiederholbar -- der Auftrag wirft weiter, der Vermerk steht.
    }

    $fehler = (string) $anzeige->fresh()?->sync_error;

    // Die Gruppe fehlt, nicht die Kampagne -- und die Kampagne steht mit
    // Namen da, damit man weiss, wo man hinmuss.
    expect($fehler)->toContain('Anzeigengruppe')
        ->and($fehler)->toContain((string) $aufbau->kampagne->name)
        // Kein Versprechen im gespeicherten Grund: was als Naechstes
        // passiert, haengt am Zustand und steht in der Oberflaeche.
        ->and($fehler)->not->toContain('geht hinaus');
});

it('gibt nach dem letzten Versuch auf, statt ewig zu warten', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    $anzeige->sync_state = SyncState::Pending;
    $anzeige->sync_error = 'Die Kampagne steht noch nicht bei Meta.';
    $anzeige->save();

    // Was die Warteschlange tut, wenn der dritte Versuch ebenfalls scheitert.
    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->failed(new RuntimeException('Zeitüberschreitung'));

    $frisch = $anzeige->fresh();

    // Ohne das bliebe die Anzeige auf "wartet" stehen -- und drehte sich in
    // der Oberflaeche, obwohl niemand mehr etwas versucht.
    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        ->and($frisch?->sync_error)->toContain('aufgegeben');
});

it('nennt beim Aufgeben den Grund, statt keinen zu haben', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Kein vermerkter Grund -- genau der Fall, der bis zum 24.09.2026
    // "kein Grund vermerkt." ergab: ein Fehlschlag, der nie durch
    // vermerkeFehler lief.
    expect($anzeige->fresh()?->sync_error)->toBeNull();

    $meldungen = [];
    Log::listen(function (MessageLogged $eintrag) use (&$meldungen): void {
        $meldungen[] = $eintrag->message;
    });

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->failed(new RuntimeException('Undefined array key "adset_id"'));

    $fehler = (string) $anzeige->fresh()?->sync_error;

    expect($fehler)->toContain('aufgegeben')
        ->and($fehler)->not->toContain('kein Grund vermerkt')
        // Ein Klassenname und eine Entwicklermeldung helfen der Praxis nicht.
        ->and($fehler)->not->toContain('RuntimeException')
        ->and($fehler)->not->toContain('Undefined array key')
        // Wo die Praxis nichts tun kann, sagen wir das -- und halten das
        // Technische im Protokoll fest.
        ->and($fehler)->toContain('bei uns')
        ->and($meldungen)->toContain('Auftrag endgueltig gescheitert');
});

it('nimmt beim Aufgeben den Klartext des Fehlers, wenn nichts vermerkt ist', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->failed(new Werbefehler(new Fehlereinordnung(
        'rejected',
        wiederholen: false,
        zustand: null,
        klartext: 'Das Bild ist zu klein für dieses Format.',
    )));

    expect((string) $anzeige->fresh()?->sync_error)
        ->toContain('Das Bild ist zu klein für dieses Format.');
});

it('laesst den vermerkten Grund vor dem Fehler des letzten Versuchs stehen', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Der beim Versuch vermerkte Grund kennt die Stelle -- eine
    // Zeitueberschreitung der Warteschlange sagt nur, dass Schluss ist.
    $anzeige->sync_error = 'Die Anzeigengruppe steht noch nicht bei Meta.';
    $anzeige->save();

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->failed(new RuntimeException('Zeitüberschreitung'));

    expect((string) $anzeige->fresh()?->sync_error)
        ->toContain('Die Anzeigengruppe steht noch nicht bei Meta.');
});

it('sagt es, wenn die Grafik nicht mehr im Speicher liegt', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Der Datensatz steht, die Datei ist fort -- auf einem Server ohne
    // dauerhafte Platte der Normalfall, nicht die Ausnahme.
    $anhang = $anzeige->vorschlag()->first()?->bild();
    Storage::disk((string) config('mrs.attachments.disk', 'local'))->delete((string) $anhang?->path);

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $frisch = $anzeige->fresh();

    // Kein "die Ursache liegt bei uns": hier gibt es einen naechsten Schritt.
    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        ->and((string) $frisch?->sync_error)->toContain('Grafik')
        ->and((string) $frisch?->sync_error)->toContain('neu');
});

it('behandelt einen Verbindungsabbruch beim Lesen als Ausfall bei Meta', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Der Auftrag sieht zuerst nach, ob die Anzeige schon dort steht -- und
    // genau dieser Lesezugriff flog frueher roh aus dem Auftrag heraus.
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));
})->throws(Werbefehler::class);

it('haelt auch eine lange Ablehnung fest, statt daran zu zerbrechen', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Metas echte Antwort vom 24.09.2026: ueber 255 Zeichen, mit Hilfelink.
    // Die Spalte fasste 255 -- das Festhalten des Fehlers warf selbst einen
    // Fehler, der Grund war fort, und der Auftrag scheiterte an etwas
    // anderem als dem eigentlichen Problem.
    $lang = 'Ein Business-Admin muss unsere Richtlinie gegen Diskriminierung lesen und akzeptieren, '
        .'bevor du Werbung schalten kannst. Diese ist in den Unternehmenseinstellungen unter '
        .'„Systemnutzer“ zu finden.Link zum Hilfebereich: '
        .'https://www.facebook.com/business/help/338925176776440';

    expect(mb_strlen($lang))->toBeGreaterThan(255);

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*' => Http::response([
            'error' => ['message' => 'Invalid parameter', 'error_user_msg' => $lang, 'code' => 100],
        ], 400),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $frisch = $anzeige->fresh();

    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        ->and((string) $frisch?->sync_error)->toBe($lang);
});

it('kappt einen masslosen Vermerk, statt ihn wegzuwerfen', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Auch eine breitere Spalte hat ein Ende. Wichtiger als der Text ist,
    // dass das Speichern nicht wirft: sonst faellt der Auftrag genau dort um,
    // wo er den Grund festhalten wollte.
    $anzeige->sync_error = str_repeat('A', 5000);
    $anzeige->save();

    expect(mb_strlen((string) $anzeige->fresh()?->sync_error))->toBeLessThanOrEqual(1000);
});

it('nimmt eine Kennung auch als Zahl an', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Metas Kennungen sind Zahlen und kommen mal als Zeichenkette, mal als
    // Zahl. Ein `is_string`-Test allein erklaerte eine geglueckte Anlage zum
    // Fehlschlag -- und die Praxis las "keine Kennung geliefert".
    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
        'graph.test/*/act_*/ads' => Http::response(['id' => 120249945031570331]),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $frisch = $anzeige->fresh();

    expect($frisch?->external_id)->toBe('120249945031570331')
        ->and($frisch?->sync_state)->toBe(SyncState::Synced);
});

it('zeigt Metas Antwort, wenn eine Kennung fehlt', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // "Keine Kennung geliefert" allein sagt nicht, was Meta stattdessen
    // geschickt hat -- und genau daran haengt, ob der Auftrag ankam.
    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
        'graph.test/*/act_*/ads' => Http::response(['success' => true]),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $fehler = (string) $anzeige->fresh()?->sync_error;

    expect($fehler)->toContain('die Anzeige')
        ->and($fehler)->toContain('success');
});

it('erkennt einen Fehler im Rumpf auch bei HTTP 200', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // **Metas Antwort vom 24.09.2026**: Statuszeile 200, `error` im Rumpf.
    // Code 31 heisst, dass die Person, der das Konto gehoert, bei Meta noch
    // etwas bestaetigen muss. Wer nur den Status prueft, laesst das durch
    // und stolpert eine Zeile spaeter ueber die fehlende Kennung -- der
    // Grund stand die ganze Zeit daneben.
    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
        'graph.test/*/act_*/ads' => Http::response(['error' => [
            'message' => 'This request requires the user to take a pending action',
            'code' => 31,
            'error_subcode' => 3858385,
            'error_user_msg' => 'Bitte authentifiziere dein Konto.',
        ]], 200),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $konto = $aufbau->werbung->konto->fresh();

    // Der Zustand gehoert ans Werbekonto: eine Sicherheitspruefung bei Meta
    // blockiert jede Anzeige, nicht diese eine.
    expect($konto?->status)->toBe(ConnectionStatus::Degraded)
        // Und im Klartext, nicht als Kurzgrund -- der Kasten zeigt ihn roh.
        ->and((string) $konto?->last_error)->toContain('authentifiziere')
        ->and((string) $konto?->last_error)->not->toContain('action_required');
});

it('haelt eine fachliche Ablehnung weiterhin als abgelehnt fest', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*' => Http::response([
            'error' => ['message' => 'Das Bild ist zu klein für dieses Format.', 'code' => 100],
        ], 400),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    expect($anzeige->fresh()?->sync_state)->toBe(SyncState::Failed);
});

it('zeigt eine gescheiterte Uebertragung an der Anzeige', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    $anzeige->sync_state = SyncState::Failed;
    $anzeige->sync_error = 'Die App ist im Entwicklungsmodus.';
    $anzeige->save();

    // Ohne diese Angabe steht der Fehler nur in der Datenbank -- die Praxis
    // sieht eine Anzeige, die aussieht wie geschaltet und es nicht ist.
    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->get(route('anzeigen.index'))
        ->assertInertia(fn ($seite) => $seite
            ->where('vorschlaege.0.uebertragung.zustand', 'failed')
            ->where('vorschlaege.0.uebertragung.fehler', 'Die App ist im Entwicklungsmodus.')
            ->where('vorschlaege.0.uebertragung.anzeige', (string) $anzeige->uuid));
});

it('meldet nichts, solange die Uebertragung geglueckt ist', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    $anzeige->sync_state = SyncState::Synced;
    $anzeige->save();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->get(route('anzeigen.index'))
        ->assertInertia(fn ($seite) => $seite->where('vorschlaege.0.uebertragung', null));
});

it('zieht eine wartende Anzeige beim Verbinden nach', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    expect($anzeige->sync_state)->toBe(SyncState::Pending);

    // Die Auswahl, wie sie nach dem Rueckweg von Meta in der Sitzung liegt.
    session()->put('werbung.auswahl', [
        'token' => Crypt::encryptString('neues-token'),
        'laeuftAb' => null,
        'konten' => [['kennung' => Werbeaufbau::KONTO, 'name' => 'Praxis', 'waehrung' => 'EUR', 'nutzbar' => true]],
    ]);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->post(route('werbung.auswaehlen'), ['kennung' => Werbeaufbau::KONTO])
        ->assertRedirect();

    Queue::assertPushed(AnzeigeUebertragen::class);
});

it('stellt eine gescheiterte Anzeige auf Verlangen erneut ein', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();
    $anzeige->sync_state = SyncState::Failed;
    $anzeige->sync_error = 'Die App ist im Entwicklungsmodus.';
    $anzeige->save();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->post(route('anzeigen.erneut', ['anzeige' => $anzeige->uuid]))
        ->assertSessionHas('erfolg');

    Queue::assertPushed(AnzeigeUebertragen::class);

    // Der alte Fehler steht der neuen Uebertragung nicht mehr im Weg.
    $frisch = $anzeige->fresh();

    expect($frisch?->sync_state)->toBe(SyncState::Pending)
        ->and($frisch?->sync_error)->toBeNull();
});

it('stellt auch eine wartende Anzeige auf Verlangen erneut ein', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    // Wartend heisst nicht: es passiert schon etwas. Geht die Kampagne nie
    // hinaus, wartet die Anzeige fuer immer -- und dann muss ein Mensch sie
    // anstossen koennen, ohne das ganze Werbekonto neu zu verbinden.
    expect($anzeige->sync_state)->toBe(SyncState::Pending);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->post(route('anzeigen.erneut', ['anzeige' => $anzeige->uuid]))
        ->assertSessionHas('erfolg');

    Queue::assertPushed(AnzeigeUebertragen::class);
});

it('laesst niemanden ohne campaigns.manage erneut uebertragen', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    $mitarbeiterin = User::factory()->fuer($aufbau->werbung->organisation, Role::Reception)->create();

    actingAs($mitarbeiterin)
        ->post(route('anzeigen.erneut', ['anzeige' => $anzeige->uuid]))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Wo laeuft diese Anzeige?
|--------------------------------------------------------------------------
|
| "Laeuft in 2 Kampagnen" beantwortet nicht die Frage, die man tatsaechlich
| hat: in welchen. Wer eine Anzeige anhalten oder ihr Budget aendern will,
| muss sonst jede Kampagne einzeln aufmachen und nachsehen.
|
*/

it('nennt die Kampagne, in der eine Anzeige laeuft', function (): void {
    $aufbau = new Anzeigenaufbau;
    $aufbau->geplanteAnzeige();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->get(route('anzeigen.index'))
        ->assertInertia(fn ($seite) => $seite->where('vorschlaege.0.laeuftIn', [$aufbau->kampagne->name]));
});

it('laesst die Liste leer, solange eine Anzeige nirgends laeuft', function (): void {
    $aufbau = new Anzeigenaufbau;
    $aufbau->vorschlagMitGrafik();

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->get(route('anzeigen.index'))
        ->assertInertia(fn ($seite) => $seite->where('vorschlaege.0.laeuftIn', []));
});

it('nennt jede Kampagne nur einmal', function (): void {
    $aufbau = new Anzeigenaufbau;
    $vorschlag = $aufbau->vorschlagMitGrafik();

    // Zwei Anzeigengruppen derselben Kampagne -- der Name gehoert trotzdem
    // nur einmal in die Liste.
    $zweite = $aufbau->gruppe->replicate();
    $zweite->external_id = 'gruppe-extern-2';
    $zweite->save();

    app(Anzeigenschaltung::class)->plane($aufbau->gruppe, $vorschlag);
    app(Anzeigenschaltung::class)->plane($zweite, $vorschlag);

    actingAs(Werbeaufbau::leitung($aufbau->werbung->organisation))
        ->get(route('anzeigen.index'))
        ->assertInertia(fn ($seite) => $seite->where('vorschlaege.0.laeuftIn', [$aufbau->kampagne->name]));
});

/*
|--------------------------------------------------------------------------
| Was leer ist, geht nicht mit
|--------------------------------------------------------------------------
|
| `ad_suggestions.description` ist nullable, und das Feld ging trotzdem
| bedingungslos in `link_data`. Meta beantwortet ein `"description": null`
| mit "Invalid parameter" -- und lehnt damit **jedes** Creative ab, zu dem
| das Modell keine Beschreibung geliefert hat.
|
| Aufgefallen ist es erst auf der Staging-Umgebung. Der Testaufbau setzt
| Ueberschrift, Text und Handlungsaufruf, aber keine Beschreibung -- der Fall
| lief hier also die ganze Zeit mit, nur nahm `Http::fake()` jeden Payload
| widerspruchslos an.
|
*/

/*
|--------------------------------------------------------------------------
| Metas Meldung ist fuer Entwickler, error_user_msg fuer Menschen
|--------------------------------------------------------------------------
|
| `error.message` ist bei fachlichen Ablehnungen oft nur "Invalid parameter".
| Der Satz, der sagt, was zu tun ist, steht daneben in `error_user_msg` --
| und der Schluessel zu Metas Doku in `error_subcode`. Beides wurde
| weggeworfen.
|
| Das hat am 23.09.2026 fuenf Runden gekostet: Eine App im Entwicklungsmodus
| kann kein Creative anlegen. An der Anzeige stand "Invalid parameter"; die
| Antwort daneben sagte es im Klartext.
|
*/

it('nimmt Metas Klartext statt der Entwicklermeldung', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*' => Http::response([
            'error' => [
                'message' => 'Invalid parameter',
                'code' => 100,
                'error_subcode' => 1885183,
                'error_user_title' => 'Beitrag der Werbeanzeige wurde mit einer App im Entwicklungsmodus erstellt',
                'error_user_msg' => 'Der Beitrag der Werbeanzeige wurde von einer App im Entwicklungsmodus erstellt. Sie muss öffentlich sein, um diese Werbeanzeige erstellen zu können.',
            ],
        ], 400),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    $fehler = (string) $anzeige->fresh()?->sync_error;

    // An der Anzeige steht der Satz, der sagt, was zu tun ist -- nicht
    // "Invalid parameter", und ohne Code: die Praxis kann nur beheben, was
    // sie lesen kann.
    expect($fehler)->toContain('Entwicklungsmodus')
        ->and($fehler)->not->toContain('1885183')
        ->and($fehler)->not->toBe('Invalid parameter');
});

it('haelt Subcode und fbtrace_id im Protokoll fest', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    /** @var list<array<string, mixed>> $protokoll */
    $protokoll = [];

    Log::listen(function (MessageLogged $eintrag) use (&$protokoll): void {
        $protokoll[] = $eintrag->context;
    });

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*' => Http::response([
            'error' => [
                'message' => 'Invalid parameter',
                'code' => 100,
                'error_subcode' => 1885183,
                'error_user_msg' => 'Die App ist im Entwicklungsmodus.',
                'fbtrace_id' => 'Af75eVNGetlA9Ek5YQ-MSwq',
            ],
        ], 400),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    // Ohne Subcode und fbtrace_id laesst sich weder Metas Doku noch Metas
    // Support durchsuchen. Genau die fehlten am 23.09.2026.
    $treffer = array_filter(
        $protokoll,
        fn (array $zusatz): bool => ($zusatz['subcode'] ?? null) === 1885183
            && ($zusatz['fbtrace_id'] ?? null) === 'Af75eVNGetlA9Ek5YQ-MSwq'
    );

    expect($treffer)->not->toBeEmpty();
});

it('faellt auf die Entwicklermeldung zurueck, wenn Meta keinen Klartext schickt', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*' => Http::response([
            'error' => ['message' => 'Das Bild ist zu klein für dieses Format.', 'code' => 100],
        ], 400),
    ]);

    app(AnzeigeUebertragen::class, [
        'organisation' => (string) $aufbau->werbung->organisation->uuid,
        'anzeige' => (string) $anzeige->uuid,
    ])->handle(app(TenantContext::class), app(Anzeigenschaltung::class));

    expect($anzeige->fresh()?->sync_error)->toBe('Das Bild ist zu klein für dieses Format.');
});

it('schickt kein leeres Feld in das Creative', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    expect($anzeige->vorschlag()->first()?->description)->toBeNull();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
        'graph.test/*/act_*/ads' => Http::response(['id' => 'anzeige-1']),
    ]);

    app(Anzeigenschaltung::class)->uebertrage($anzeige, $aufbau->werbung->konto);

    Http::assertSent(function ($anfrage): bool {
        if (! str_ends_with((string) $anfrage->url(), '/adcreatives')) {
            return false;
        }

        $inhalt = json_decode((string) $anfrage->data()['object_story_spec'], true);

        return is_array($inhalt) && ! array_key_exists('description', $inhalt['link_data']);
    });
});

it('schickt eine vorhandene Beschreibung mit', function (): void {
    $aufbau = new Anzeigenaufbau;
    $anzeige = $aufbau->geplanteAnzeige();

    $vorschlag = $anzeige->vorschlag()->firstOrFail();
    $vorschlag->description = 'In Ruhe und ohne Druck.';
    $vorschlag->save();

    Http::fake([
        'graph.test/*/act_*/ads?*' => Http::response(Werbeaufbau::seite([])),
        'graph.test/*/act_*/adimages' => Http::response(['images' => ['anzeige.png' => ['hash' => 'bildhash-1']]]),
        'graph.test/*/act_*/adcreatives' => Http::response(['id' => 'creative-1']),
        'graph.test/*/act_*/ads' => Http::response(['id' => 'anzeige-1']),
    ]);

    app(Anzeigenschaltung::class)->uebertrage($anzeige, $aufbau->werbung->konto);

    Http::assertSent(function ($anfrage): bool {
        if (! str_ends_with((string) $anfrage->url(), '/adcreatives')) {
            return false;
        }

        $inhalt = json_decode((string) $anfrage->data()['object_story_spec'], true);

        return is_array($inhalt) && ($inhalt['link_data']['description'] ?? null) === 'In Ruhe und ohne Druck.';
    });
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
