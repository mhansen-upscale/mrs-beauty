<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\SyncState;
use App\Jobs\KampagneUebertragen;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\Location;
use App\Models\Treatment;
use App\Tenancy\TenantContext;
use App\Werbung\Verwaltung\Kampagnenname;
use App\Werbung\Verwaltung\Kampagnenplan;
use App\Werbung\Verwaltung\Kampagnenverwaltung;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use RuntimeException;
use Tests\Feature\Werbung\Werbeaufbau;

/*
|--------------------------------------------------------------------------
| WP-27 -- Kampagnenverwaltung
|--------------------------------------------------------------------------
|
| Die Abnahmekriterien aus specs/WP-27-kampagnenverwaltung.md.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-17 09:00:00', 'UTC'));
    config()->set('mrs.meta.graph_url', 'https://graph.test');
    config()->set('mrs.meta.api_version', 'v21.0');
});

function werbestandort(): Location
{
    return Location::factory()->create(['name' => 'Hauptstandort', 'city' => 'Hamburg', 'is_active' => true]);
}

/**
 * @param  array<string, mixed>  $abweichend
 * @return array<string, mixed>
 */
function kampagnenformular(Location $standort, array $abweichend = []): array
{
    return array_merge([
        'ziel' => 'OUTCOME_LEADS',
        'tagesbudget' => 2500,
        'beginn' => '2026-10-01',
        'ende' => '2026-10-31',
        'standort' => (string) $standort->uuid,
        'umkreis' => 20,
        'altervon' => 30,
        'alterbis' => 55,
        'geschlecht' => 'weiblich',
    ], $abweichend);
}

/*
|--------------------------------------------------------------------------
| Pruefungen vor der Uebermittlung
|--------------------------------------------------------------------------
*/

it('laesst kein Mindestalter unter 18 zu', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, ['altervon' => 16]))
        ->assertSessionHasErrors('altervon');

    expect(AdCampaign::query()->count())->toBe(0);
});

it('laesst kein Budget unter der Untergrenze zu', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, ['tagesbudget' => 50]))
        ->assertSessionHasErrors('tagesbudget');
});

it('kennt kein Feld fuer Interessen', function (): void {
    // "Botox" als Interesse waere eine Behandlungsbezeichnung Richtung Meta
    // (Regel 2) -- in einem Feld, an das niemand denkt.
    $regeln = Kampagnenplan::regeln(null);

    expect(array_keys($regeln))->not->toContain('interessen')
        ->and(array_keys($regeln))->not->toContain('interests')
        // Die Liste ist abschliessend: ein neues Feld faellt hier auf, bevor
        // es unbemerkt Richtung Meta geht. `name` und `gruppenname` kamen am
        // 23.09.2026 dazu und sind gegen den Katalog geprueft.
        ->and(array_keys($regeln))->toBe([
            'ziel', 'tagesbudget', 'beginn', 'ende', 'standort', 'umkreis', 'altervon', 'alterbis', 'geschlecht',
            'name', 'gruppenname',
        ]);
});

it('laesst kein Ziel ausserhalb der Liste zu', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, ['ziel' => 'OUTCOME_SALES']))
        ->assertSessionHasErrors('ziel');
});

it('laesst eine Laufzeit nicht rueckwaerts laufen', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, [
            'beginn' => '2026-10-01',
            'ende' => '2026-09-01',
        ]))
        ->assertSessionHasErrors('ende');
});

/*
|--------------------------------------------------------------------------
| Name
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Den Namen waehlt die Praxis -- bis auf eine Ausnahme
|--------------------------------------------------------------------------
|
| Bis zum 23.09.2026 erzeugte das Produkt den Namen vollstaendig. Das war zu
| streng: "Anfragen sammeln · Oktober 2026 · Hamburg" unterscheidet drei
| Kampagnen desselben Monats nicht.
|
| Was bleibt, ist die Ausnahme mit dem eigentlichen Grund (Regel 2): Diese
| Namen liegen bei Meta offen und frieren als attribution_snapshot am Termin
| ein. Eine Katalogbezeichnung darin setzt einen Behandlungsnamen neben einen
| Kontakt -- abgelehnt wird am Feld, nicht in der Warteschlange.
|
*/

it('uebernimmt einen selbst gewaehlten Kampagnennamen', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, [
            'name' => 'Herbstaktion Eimsbüttel',
        ]))
        ->assertRedirect();

    expect((string) AdCampaign::query()->first()?->name)->toContain('Herbstaktion Eimsbüttel');
});

it('erzeugt den Namen weiterhin, wenn keiner angegeben ist', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort))
        ->assertRedirect();

    $name = (string) AdCampaign::query()->first()?->name;

    expect($name)->toContain('Anfragen sammeln')
        ->and($name)->toContain('Oktober 2026')
        ->and($name)->toContain('Hamburg');
});

it('weist einen Kampagnennamen mit Katalogbezeichnung am Feld zurueck', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, [
            'name' => 'Botox Herbst',
        ]))
        ->assertSessionHasErrors('name');

    expect(AdCampaign::query()->count())->toBe(0);
});

it('uebernimmt einen selbst gewaehlten Namen fuer die Anzeigengruppe', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, [
            'gruppenname' => 'Frauen 30 bis 55',
        ]))
        ->assertRedirect();

    expect((string) AdSet::query()->first()?->name)->toContain('Frauen 30 bis 55');
});

it('weist auch den Gruppennamen mit Katalogbezeichnung zurueck', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Treatment::factory()->create(['name' => 'Hyaluron', 'is_active' => true]);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, [
            'gruppenname' => 'Hyaluron Zielgruppe',
        ]))
        ->assertSessionHasErrors('gruppenname');
});

it('traegt das Merkmal auch in einem selbst gewaehlten Namen', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, [
            'name' => 'Herbstaktion Eimsbüttel',
        ]));

    $kampagne = AdCampaign::query()->firstOrFail();

    // Ohne das Merkmal legte ein wiederholter Auftrag eine zweite Kampagne
    // mit zweitem Budget an -- Metas API kennt keinen Idempotenzschluessel.
    expect(Kampagnenname::merkmalAus((string) $kampagne->name))->toBe($kampagne->client_token);
});

it('setzt keine Katalogbezeichnung in den Namen', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);
    Treatment::factory()->create(['name' => 'Hyaluron', 'is_active' => true]);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort))
        ->assertRedirect();

    $name = (string) AdCampaign::query()->first()?->name;

    foreach (Treatment::aktiveNamen() as $behandlung) {
        expect($name)->not->toContain($behandlung);
    }
});

it('traegt ein wiederauffindbares Merkmal im Namen', function (): void {
    $merkmal = Kampagnenname::merkmal();

    $name = Kampagnenname::fuer('OUTCOME_LEADS', CarbonImmutable::parse('2026-10-01'), null, $merkmal);

    expect(Kampagnenname::merkmalAus($name))->toBe($merkmal)
        ->and(Kampagnenname::merkmalAus('Irgendein fremder Name'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Uebertragung
|--------------------------------------------------------------------------
*/

it('legt lokal an und stellt einen Auftrag ein, ohne Meta zu rufen', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    Http::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort))
        ->assertRedirect();

    $kampagne = AdCampaign::query()->first();

    expect($kampagne?->sync_state)->toBe(SyncState::Pending)
        ->and($kampagne?->managed_by_us)->toBeTrue()
        // Pausiert angelegt: eine Kampagne, die im Moment des Anlegens Geld
        // ausgibt, laesst keinen Blick darauf zu, bevor sie es tut.
        ->and($kampagne?->status)->toBe('PAUSED')
        ->and(AdSet::query()->first()?->age_min)->toBe(30);

    Queue::assertPushed(KampagneUebertragen::class);

    // Entscheidung B2: kein Fremdsystemaufruf im Anfragezyklus.
    Http::assertNothingSent();
});

it('traegt Metas Kennung ein und meldet uebertragen', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    // Der Anlegeaufruf geht auf denselben Pfad wie das Nachsehen -- die
    // Sequenz unterscheidet sie ueber die Reihenfolge. **Ein** Http::fake:
    // ein zweites auf dasselbe Muster ersetzt das erste nicht.
    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([]))
            ->push(['id' => 'camp-1']),
        'graph.test/*/search*' => Http::response(Werbeaufbau::seite([['key' => '2696', 'name' => 'Hamburg']])),
        'graph.test/*/adsets*' => Http::response(['id' => 'adset-1']),
    ]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    alsMandant($aufbau->organisation);

    expect(AdCampaign::query()->first()?->external_id)->toBe('camp-1')
        ->and(AdCampaign::query()->first()?->sync_state)->toBe(SyncState::Synced)
        ->and(AdSet::query()->first()?->external_id)->toBe('adset-1');
});

it('legt bei einem zweiten Lauf keine zweite Kampagne an', function (): void {
    // **Der teuerste Fehler dieses Pakets.** Metas Marketing-API kennt keinen
    // Idempotenzschluessel; ein Auftrag, dessen Antwort verlorenging, legte
    // sonst eine zweite Kampagne mit zweitem Budget an.
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();
    $name = (string) $kampagne->name;

    // Der erste Versuch war bei Meta erfolgreich -- nur die Antwort kam nie
    // an. Das Nachsehen findet die Kampagne ueber das Merkmal im Namen.
    Http::fake([
        'graph.test/*/campaigns*' => Http::response(Werbeaufbau::seite([
            ['id' => 'camp-schon-da', 'name' => $name],
        ])),
        'graph.test/*/search*' => Http::response(Werbeaufbau::seite([['key' => '2696']])),
        'graph.test/*/adsets*' => Http::response(['id' => 'adset-1']),
    ]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    alsMandant($aufbau->organisation);

    expect(AdCampaign::query()->count())->toBe(1)
        ->and(AdCampaign::query()->first()?->external_id)->toBe('camp-schon-da');

    // Und kein POST auf /campaigns: gefunden statt angelegt.
    Http::assertNotSent(fn ($anfrage): bool => $anfrage->method() === 'POST' && str_contains($anfrage->url(), '/campaigns'));
});

it('wiederholt ein Rate-Limit beim Uebertragen', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    Http::fake(['graph.test/*' => Http::response(Werbeaufbau::fehler(17), 429)]);

    expect(fn () => (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class)))
        ->toThrow(Werbefehler::class);
});

it('wiederholt ein totes Token nicht und setzt das Werbekonto auf expired', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    Http::fake(['graph.test/*' => Http::response(Werbeaufbau::fehler(190), 401)]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    alsMandant($aufbau->organisation);

    // **Die Kampagne bleibt wartend, nicht fehlgeschlagen.** Ein
    // Verbindungsfehler ist nicht ihre Schuld: gewollt ist die Aenderung
    // weiterhin, und sobald der Zugang steht, geht sie hinaus. Der Hinweis
    // haengt am Werbekonto, wo er hingehoert.
    expect($aufbau->konto->fresh()?->status)->toBe(ConnectionStatus::Expired)
        ->and(AdCampaign::query()->first()?->sync_state)->toBe(SyncState::Pending)
        ->and(AdCampaign::query()->first()?->sync_error)->toBeNull();
});

it('zieht beim erneuten Verbinden liegengebliebene Aenderungen nach', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    // Die Auswahl, wie sie nach dem Rueckweg von Meta in der Sitzung liegt.
    session()->put('werbung.auswahl', [
        'token' => Crypt::encryptString('neues-token'),
        'laeuftAb' => null,
        'konten' => [['kennung' => Werbeaufbau::KONTO, 'name' => 'Praxis', 'waehrung' => 'EUR', 'nutzbar' => true]],
    ]);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.auswaehlen'), ['kennung' => Werbeaufbau::KONTO])
        ->assertRedirect();

    // Eine Aenderung, die an einem abgelaufenen Zugang haengen blieb, wartet
    // -- nicht darauf, dass jemand sie erneut eintippt.
    Queue::assertPushed(KampagneUebertragen::class);
});

/*
|--------------------------------------------------------------------------
| Fertig heisst: Kampagne **und** Gruppe
|--------------------------------------------------------------------------
|
| Die Kampagne wurde als `Synced` gespeichert, sobald ihre eigene Kennung da
| war -- die Anzeigengruppe entsteht erst danach. Bricht dieser zweite
| Schritt ab, bleibt ein halber Zustand: bei Meta eine Kampagne ohne Gruppe,
| lokal eine Kampagne, die sich fuer fertig haelt.
|
| Und weil beide Wiederanlaeufe `Pending` suchen, holt sie danach **keiner**
| mehr ein. Jede Anzeige scheitert dann auf ewig mit `adset_not_synced`.
| Genau so lag es am 23.09.2026 auf der Staging-Umgebung.
|
*/

/*
|--------------------------------------------------------------------------
| Was Meta ohnehin ablehnt, nimmt das Formular gar nicht erst an
|--------------------------------------------------------------------------
|
| **Umkreis.** Meta verlangt fuer eine Stadt mindestens 10 Meilen, also
| 16 km. Die Konfiguration liess 1 km zu -- eine Praxis konnte einen Wert
| eintragen, der garantiert scheitert, und erfuhr es Stunden spaeter aus der
| Warteschlange. Am 23.09.2026 lag genau deshalb eine Kampagne mit 15 km.
|
| **Gebotsstrategie.** Ohne ausdrueckliche Angabe legte Meta die Kampagne mit
| `LOWEST_COST_WITH_BID_CAP` an -- und die verlangt an *jeder* Anzeigengruppe
| ein `bid_amount`, das dieses Produkt nirgends erhebt. Jede Gruppe scheiterte
| daran, auch mit gueltigem Umkreis.
|
*/

it('schaltet Metas Zielgruppenerweiterung ausdruecklich ab', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([]))
            ->push(['id' => 'kampagne-1']),
        'graph.test/*/search*' => Http::response(['data' => [['key' => '560419', 'name' => 'Hamburg']]]),
        'graph.test/*/adsets*' => Http::response(['id' => 'gruppe-1']),
    ]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    // **Null, nicht eins.** Advantage Audience erweitert die Zielgruppe ueber
    // das hinaus, was die Praxis eingestellt hat -- auch die Altersuntergrenze.
    // Bei aesthetischen Behandlungen ist genau die keine Empfehlung, sondern
    // eine Grenze. Meta verlangt die Angabe seit 2026 ausdruecklich.
    Http::assertSent(function ($anfrage): bool {
        if (! str_ends_with((string) $anfrage->url(), '/adsets')) {
            return false;
        }

        $ziel = json_decode((string) $anfrage->data()['targeting'], true);

        return is_array($ziel) && ($ziel['targeting_automation']['advantage_audience'] ?? null) === 0;
    });
});

it('weist einen Umkreis unterhalb von Metas Untergrenze am Feld zurueck', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, ['umkreis' => 15]))
        ->assertSessionHasErrors('umkreis');

    expect(AdCampaign::query()->count())->toBe(0);
});

it('nimmt Metas Untergrenze selbst an', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort, ['umkreis' => 16]))
        ->assertSessionHasNoErrors();

    expect(AdCampaign::query()->count())->toBe(1);
});

it('legt die Kampagne ohne Gebotsbegrenzung an', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([]))
            ->push(['id' => 'kampagne-1']),
        'graph.test/*/search*' => Http::response(['data' => [['key' => '560419', 'name' => 'Hamburg']]]),
        'graph.test/*/adsets*' => Http::response(['id' => 'gruppe-1']),
    ]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    // Ohne diese Angabe waehlt Meta eine Strategie, die an jeder
    // Anzeigengruppe ein Gebot verlangt -- das dieses Produkt nicht erhebt.
    Http::assertSent(fn ($anfrage): bool => str_ends_with((string) $anfrage->url(), '/campaigns')
        && ($anfrage->data()['bid_strategy'] ?? null) === 'LOWEST_COST_WITHOUT_CAP');
});

it('meldet die Kampagne erst als uebertragen, wenn auch die Gruppe steht', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([]))
            ->push(['id' => 'kampagne-1']),
        'graph.test/*/search*' => Http::response(['data' => [['key' => '560419', 'name' => 'Hamburg']]]),
        'graph.test/*/adsets*' => Http::response(Werbeaufbau::fehler(100, 0, 'Die Zielgruppe ist zu klein.'), 400),
    ]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    alsMandant($aufbau->organisation);

    $frisch = AdCampaign::query()->first();

    // Nicht "fertig": sonst holt sie kein Wiederanlauf mehr ein.
    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        ->and($frisch?->sync_error)->toBe('Die Zielgruppe ist zu klein.')
        // Metas Kennung bleibt trotzdem stehen -- sie ist dort angelegt, und
        // ein zweiter Lauf soll keine zweite Kampagne erzeugen.
        ->and($frisch?->external_id)->toBe('kampagne-1');
});

it('meldet eine Kampagne ohne Anzeigengruppe als Fehler, nicht als Erfolg', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    // Eine Kampagne ohne Gruppe ist ein Datenfehler, kein Normalfall. Bisher
    // kehrte die Uebertragung dabei wortlos zurueck und verbuchte Erfolg.
    AdSet::query()->delete();

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([]))
            ->push(['id' => 'kampagne-1']),
    ]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    alsMandant($aufbau->organisation);

    expect(AdCampaign::query()->first()?->sync_state)->toBe(SyncState::Failed);
});

it('gibt bei der Kampagne nach dem letzten Versuch auf', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->failed(new RuntimeException('Zeitüberschreitung'));

    alsMandant($aufbau->organisation);

    $frisch = AdCampaign::query()->first();

    // Sonst bliebe sie auf "wartet" stehen, obwohl niemand mehr etwas
    // versucht -- und der Wiederanlauf stellte sie bei jedem Verbinden
    // erneut ein, mit demselben Ausgang.
    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        ->and($frisch?->sync_error)->toContain('mehrfach');
});

it('zeigt eine fachliche Ablehnung im Klartext', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([]))
            ->push(Werbeaufbau::fehler(100, 1885183, 'Ad account is not allowed to run ads for this category.'), 400),
    ]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    alsMandant($aufbau->organisation);

    $frisch = AdCampaign::query()->first();

    expect($frisch?->sync_state)->toBe(SyncState::Failed)
        // Ein Code hilft der Praxis nicht. Sie kann nur beheben, was sie
        // lesen kann.
        ->and($frisch?->sync_error)->toBe('Ad account is not allowed to run ads for this category.');
});

it('nennt einen Ort, den Meta nicht kennt, beim Namen', function (): void {
    $aufbau = new Werbeaufbau;
    $standort = werbestandort();

    Queue::fake();
    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->post(route('werbung.kampagne.anlegen'), kampagnenformular($standort));

    $kampagne = AdCampaign::query()->firstOrFail();

    Http::fake([
        'graph.test/*/campaigns*' => Http::sequence()
            ->push(Werbeaufbau::seite([]))
            ->push(['id' => 'camp-1']),
        // Meta findet den Ort nicht.
        'graph.test/*/search*' => Http::response(Werbeaufbau::seite([])),
    ]);

    (new KampagneUebertragen((string) $aufbau->organisation->uuid, (string) $kampagne->uuid))
        ->handle(app(TenantContext::class), app(Kampagnenverwaltung::class));

    alsMandant($aufbau->organisation);

    // Lieber ein klarer Fehler als eine Kampagne, die deutschlandweit
    // ausliefert.
    expect(AdCampaign::query()->first()?->sync_error)
        ->toContain('Meta kennt den Ort dieses Standorts nicht');
});

/*
|--------------------------------------------------------------------------
| Aendern
|--------------------------------------------------------------------------
*/

it('pausiert lokal sofort und uebertraegt danach', function (): void {
    $aufbau = new Werbeaufbau;

    $kampagne = AdCampaign::query()->create([
        'ad_account_id' => $aufbau->konto->getKey(),
        'external_id' => 'camp-1',
        'name' => 'Anfragen sammeln · Oktober 2026',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => 2500,
    ]);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->patch(route('werbung.kampagne.aendern', ['kampagne' => $kampagne->uuid]), ['zustand' => 'PAUSED'])
        ->assertRedirect();

    $frisch = $kampagne->fresh();

    expect($frisch?->status)->toBe('PAUSED')
        ->and($frisch?->sync_state)->toBe(SyncState::Pending);

    Queue::assertPushed(KampagneUebertragen::class);
});

it('aendert das Budget einer fremden Kampagne, aber nicht ihren Namen', function (): void {
    $aufbau = new Werbeaufbau;

    $kampagne = AdCampaign::query()->create([
        'ad_account_id' => $aufbau->konto->getKey(),
        'external_id' => 'camp-fremd',
        'name' => 'Botox Herbst',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
        'daily_budget' => 2500,
        'managed_by_us' => false,
    ]);

    Queue::fake();

    actingAs(Werbeaufbau::leitung($aufbau->organisation))
        ->patch(route('werbung.kampagne.aendern', ['kampagne' => $kampagne->uuid]), [
            'tagesbudget' => 4000,
            'name' => 'Neuer Name',
        ])
        ->assertRedirect();

    $frisch = $kampagne->fresh();

    expect($frisch?->daily_budget)->toBe(4000)
        // Fremde Namen aendern wir nicht -- das Feld existiert nicht.
        ->and($frisch?->name)->toBe('Botox Herbst');
});

it('schickt beim Aendern nur Zustand und Budget', function (): void {
    $aufbau = new Werbeaufbau;

    $kampagne = AdCampaign::query()->create([
        'ad_account_id' => $aufbau->konto->getKey(),
        'external_id' => 'camp-1',
        'name' => 'Botox Herbst',
        'status' => 'PAUSED',
        'effective_status' => 'PAUSED',
        'daily_budget' => 4000,
    ]);

    Http::fake(['graph.test/*' => Http::response(['success' => true])]);

    app(Kampagnenverwaltung::class)->uebertrageAenderung($kampagne, $aufbau->konto);

    Http::assertSent(function ($anfrage): bool {
        $daten = $anfrage->data();

        return $anfrage->method() === 'POST'
            && str_contains($anfrage->url(), '/camp-1')
            && ($daten['status'] ?? null) === 'PAUSED'
            && ($daten['daily_budget'] ?? null) === '4000'
            // Der Name geht nie mit hinaus.
            && ! array_key_exists('name', $daten);
    });

    expect($kampagne->fresh()?->sync_state)->toBe(SyncState::Synced);
});

it('laesst zwei Mandanten einander nichts aendern', function (): void {
    $eine = new Werbeaufbau(organisation('Praxis A'));

    $kampagne = AdCampaign::query()->create([
        'ad_account_id' => $eine->konto->getKey(),
        'external_id' => 'camp-a',
        'name' => 'Kampagne A',
        'status' => 'ACTIVE',
        'effective_status' => 'ACTIVE',
    ]);

    $andere = alsMandant(organisation('Praxis B'));

    Queue::fake();

    actingAs(Werbeaufbau::leitung($andere))
        ->patch(route('werbung.kampagne.aendern', ['kampagne' => $kampagne->uuid]), ['zustand' => 'PAUSED'])
        ->assertNotFound();
});
