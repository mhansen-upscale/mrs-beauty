<?php

declare(strict_types=1);

use App\Attribution\Auswertung;
use App\Attribution\Auswertungszeile;
use App\Attribution\Meta\Conversionsversand;
use App\Attribution\Meta\Konversionsereignis;
use App\Enums\AppointmentStatus;
use App\Enums\AttributionModel;
use App\Enums\Aufschluesselung;
use App\Enums\InsightLevel;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\AdInsight;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\AttributionTouch;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Practitioner;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Werbung\Werbeaufbau;

/*
|--------------------------------------------------------------------------
| WP-32b -- Kennzahlen, ROI-Dashboard und Conversions API
|--------------------------------------------------------------------------
|
| Die Abnahmekriterien aus specs/WP-32b-roi-dashboard.md, darunter die
| Testfaelle 7, 8 und 10 aus docs/fachlogik/attribution.md.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-17 09:00:00', 'UTC'));
    config()->set('mrs.meta.graph_url', 'https://graph.test');
});

/**
 * Die Bausteine, die ein Termin braucht -- einmal je Praxis.
 *
 * @return array{art: AppointmentType, behandler: Practitioner, standort: Location}
 */
function terminbausteine(): array
{
    return [
        'art' => AppointmentType::factory()->create(),
        'behandler' => Practitioner::factory()->create(),
        'standort' => Location::factory()->create(),
    ];
}

/**
 * Ein Termin mit gegebenem Zustand und gegebener Kampagne.
 *
 * @param  array{art: AppointmentType, behandler: Practitioner, standort: Location}  $bausteine
 */
function auswertungstermin(array $bausteine, Contact $kontakt, AppointmentStatus $zustand, ?string $kampagne): Appointment
{
    return Appointment::query()->create([
        'contact_id' => $kontakt->getKey(),
        'appointment_type_id' => $bausteine['art']->getKey(),
        'practitioner_id' => $bausteine['behandler']->getKey(),
        'location_id' => $bausteine['standort']->getKey(),
        'status' => $zustand->value,
        'booked_via' => 'public',
        'starts_at' => CarbonImmutable::now()->subDays(2),
        'ends_at' => CarbonImmutable::now()->subDays(2)->addHour(),
        'blocked_from' => CarbonImmutable::now()->subDays(2),
        'blocked_until' => CarbonImmutable::now()->subDays(2)->addHour(),
        'attribution_campaign_id' => $kampagne,
    ]);
}

/**
 * Eine Testkampagne, von Hand nachgerechnet.
 *
 * 100,00 EUR Ausgaben, 4 Anfragen, 3 gebuchte Beratungen, 2 erschienen,
 * 1 gewonnen zu 800,00 EUR Durchschnittsumsatz.
 *
 *   Cost per Lead    = 10000 / 4 = 2500  (25,00 EUR)
 *   Cost per Consult = 10000 / 3 = 3333,33
 *   CAC              = 10000 / 1 = 10000 (100,00 EUR)
 *   ROAS             = 80000 / 10000 = 8,0
 *   Show-Rate        = 2 / 3 = 66,67 %
 *   No-Show-Quote    = 1 / 3 = 33,33 %
 */
function testkampagne(): Organization
{
    $aufbau = new Werbeaufbau;

    AdInsight::query()->create([
        'ad_account_id' => $aufbau->konto->getKey(),
        'level' => InsightLevel::Campaign->value,
        'external_id' => 'camp-1',
        'stat_date' => CarbonImmutable::now()->subDays(3)->toDateString(),
        'spend_minor' => 10000,
    ]);

    $behandlung = Treatment::factory()->create(['name' => 'Faltenbehandlung', 'avg_revenue_cents' => 80000]);
    $bausteine = terminbausteine();

    $zustaende = [
        AppointmentStatus::Attended,
        AppointmentStatus::Attended,
        AppointmentStatus::NoShow,
        AppointmentStatus::Cancelled,
    ];

    foreach ($zustaende as $nummer => $zustand) {
        $kontakt = Contact::factory()->create();

        auswertungstermin($bausteine, $kontakt, $zustand, 'camp-1');

        Lead::query()->create([
            'contact_id' => $kontakt->getKey(),
            'treatment_id' => $behandlung->getKey(),
            'status' => $nummer === 0 ? LeadStatus::Won->value : LeadStatus::Scheduled->value,
            'source' => 'booking_page',
            'last_activity_at' => CarbonImmutable::now(),
            'first_response_seconds' => [120, 300, 900, 60][$nummer],
        ]);
    }

    return $aufbau->organisation;
}

/*
|--------------------------------------------------------------------------
| Kennzahlen
|--------------------------------------------------------------------------
*/

it('zaehlt Anfragen, Beratungen, Erschienene und Abschluesse nach den Definitionen', function (): void {
    testkampagne();

    $zeilen = app(Auswertung::class)->zeilen(
        CarbonImmutable::now()->subDays(30),
        CarbonImmutable::now(),
    );

    $kampagne = $zeilen[0];

    expect($kampagne->leads)->toBe(4)
        // Gebucht: pending, confirmed, attended -- eine Absage ist keine
        // Buchung mehr.
        ->and($kampagne->gebucht)->toBe(2)
        ->and($kampagne->erschienen)->toBe(2)
        ->and($kampagne->nichtErschienen)->toBe(1)
        // **Gewonnen heisst erschienen**, nicht gebucht.
        ->and($kampagne->abschluesse)->toBe(1);
});

it('rechnet die Kennzahlen einer von Hand nachgerechneten Testkampagne', function (): void {
    // **Testfall 10.**
    testkampagne();

    $zeilen = app(Auswertung::class)->zeilen(
        CarbonImmutable::now()->subDays(30),
        CarbonImmutable::now(),
    );

    $kampagne = $zeilen[0];

    expect($kampagne->ausgabenMinor)->toBe(10000)
        ->and($kampagne->umsatzCents)->toBe(80000)
        ->and($kampagne->costPerLead())->toBe(2500.0)
        ->and(round((float) $kampagne->costPerConsult(), 2))->toBe(5000.0)
        ->and($kampagne->cac())->toBe(10000.0)
        ->and($kampagne->roas())->toBe(8.0)
        ->and(round((float) $kampagne->showRate(), 2))->toBe(100.0)
        ->and(round((float) $kampagne->noShowQuote(), 2))->toBe(33.33);
});

it('liefert bei leerem Nenner einen Strich statt einer Null', function (): void {
    $leer = new Auswertungszeile('x', 'Ohne alles');

    expect($leer->showRate())->toBeNull()
        ->and($leer->noShowQuote())->toBeNull()
        ->and($leer->costPerLead())->toBeNull()
        ->and($leer->cac())->toBeNull()
        // Ohne Ausgaben keine Aussage -- nicht unendlich und nicht null.
        ->and($leer->roas())->toBeNull();
});

it('nimmt fuer Speed-to-Lead den Median, nicht den Mittelwert', function (): void {
    testkampagne();

    $zeilen = app(Auswertung::class)->zeilen(
        CarbonImmutable::now()->subDays(30),
        CarbonImmutable::now(),
    );

    // 60, 120, 300, 900 -> Median (120 + 300) / 2 = 210.
    // Der Mittelwert waere 345: ein einzelner Vorgang, der liegen blieb,
    // zieht ihn so weit, dass die Zahl nichts mehr ueber den Alltag sagt.
    expect($zeilen[0]->speedToLead)->toBe(210);
});

it('laesst die Kostenkennzahlen leer, wenn nicht nach Kampagne gruppiert wird', function (): void {
    // Meta rechnet je Kampagne ab, nicht je Behandler. Eine gerechnete
    // Verteilung waere erfunden -- und erfundene Zahlen sind schlimmer als
    // fehlende.
    testkampagne();

    $zeilen = app(Auswertung::class)->zeilen(
        CarbonImmutable::now()->subDays(30),
        CarbonImmutable::now(),
        Aufschluesselung::Behandler,
    );

    expect($zeilen)->not->toBeEmpty()
        ->and($zeilen[0]->ausgabenMinor)->toBeNull()
        ->and($zeilen[0]->costPerLead())->toBeNull()
        ->and($zeilen[0]->roas())->toBeNull()
        // Die Konversionsseite steht trotzdem da.
        ->and($zeilen[0]->leads)->toBe(4);
});

it('nennt eine Buchung ohne Kampagne Quelle unbekannt', function (): void {
    new Werbeaufbau;
    $kontakt = Contact::factory()->create();

    auswertungstermin(terminbausteine(), $kontakt, AppointmentStatus::Confirmed, null);

    $zeilen = app(Auswertung::class)->zeilen(
        CarbonImmutable::now()->subDays(30),
        CarbonImmutable::now(),
    );

    expect($zeilen[0]->bezeichnung)->toBe('Quelle unbekannt');
});

it('haelt eine Zeile ohne Zahlen bei Meta fuer unbekannt, nicht fuer null Euro', function (): void {
    // "0,00 EUR je Anfrage" saehe aus, als waeren diese Anfragen umsonst
    // gekommen -- dieselbe Unterscheidung wie in WP-28 zwischen "keine
    // Auslieferung" und "Nullen".
    new Werbeaufbau;
    $kontakt = Contact::factory()->create();

    auswertungstermin(terminbausteine(), $kontakt, AppointmentStatus::Confirmed, null);

    $zeilen = app(Auswertung::class)->zeilen(CarbonImmutable::now()->subDays(30), CarbonImmutable::now());

    expect($zeilen[0]->ausgabenMinor)->toBeNull()
        ->and($zeilen[0]->costPerLead())->toBeNull();
});

it('teilt die Kosten nur durch zugeordnete Anfragen', function (): void {
    // **`docs/fachlogik/attribution.md`**: "Leads: Anzahl `leads` mit
    // zugeordneter Kampagne im Zeitraum". Wer die Anfragen ohne Quelle
    // mitzaehlt, drueckt die Kosten je Anfrage kuenstlich -- in die Richtung,
    // die schmeichelt.
    testkampagne();

    $bausteine = terminbausteine();

    // Zwei Buchungen ohne jede Quelle dazu.
    foreach (range(1, 2) as $n) {
        $kontakt = Contact::factory()->create();
        auswertungstermin($bausteine, $kontakt, AppointmentStatus::Attended, null);

        Lead::query()->create([
            'contact_id' => $kontakt->getKey(),
            'status' => LeadStatus::Scheduled->value,
            'source' => 'phone',
            'last_activity_at' => CarbonImmutable::now(),
        ]);
    }

    $auswertung = app(Auswertung::class);
    $zeilen = $auswertung->zeilen(CarbonImmutable::now()->subDays(30), CarbonImmutable::now());

    $gesamt = $auswertung->summe($zeilen);
    $zugeordnet = $auswertung->zugeordnet($zeilen);

    expect($gesamt->leads)->toBe(6)
        ->and($zugeordnet->leads)->toBe(4)
        // 100,00 EUR auf vier zugeordnete Anfragen, nicht auf sechs.
        ->and($zugeordnet->costPerLead())->toBe(2500.0)
        ->and($gesamt->costPerLead())->not->toBe($zugeordnet->costPerLead());
});

it('bringt die Summe mit den Zeilen zur Deckung', function (): void {
    testkampagne();

    $auswertung = app(Auswertung::class);
    $zeilen = $auswertung->zeilen(CarbonImmutable::now()->subDays(30), CarbonImmutable::now());
    $summe = $auswertung->summe($zeilen);

    expect($summe->leads)->toBe(array_sum(array_map(fn ($z): int => $z->leads, $zeilen)))
        ->and($summe->ausgabenMinor)->toBe(10000);
});

it('veraendert mit dem Modell die Zuordnung', function (): void {
    new Werbeaufbau;
    $kontakt = Contact::factory()->create();

    foreach ([['tage' => 10, 'kampagne' => 'camp-erste'], ['tage' => 2, 'kampagne' => 'camp-letzte']] as $glied) {
        AttributionTouch::query()->create([
            'visitor_id' => 'besucher-1',
            'utm_source' => 'facebook',
            'campaign_external_id' => $glied['kampagne'],
            'occurred_at' => CarbonImmutable::now()->subDays($glied['tage']),
            'contact_id' => $kontakt->getKey(),
        ]);
    }

    auswertungstermin(terminbausteine(), $kontakt, AppointmentStatus::Confirmed, 'camp-letzte');

    $auswertung = app(Auswertung::class);
    $von = CarbonImmutable::now()->subDays(30);
    $bis = CarbonImmutable::now();

    // Ohne Modell gilt der eingefrorene Stand.
    expect($auswertung->zeilen($von, $bis)[0]->schluessel)->toBe('camp-letzte')
        // Mit First Touch wird neu gerechnet -- der Snapshot bleibt
        // unangetastet, er ist die Aussage von damals.
        ->and($auswertung->zeilen($von, $bis, Aufschluesselung::Kampagne, AttributionModel::FirstTouch)[0]->schluessel)
        ->toBe('camp-erste');
});

/*
|--------------------------------------------------------------------------
| Conversions API
|--------------------------------------------------------------------------
*/

it('laesst nur Lead, Schedule und Contact hinaus', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    expect(Konversionsereignis::ERLAUBT)->toBe(['Lead', 'Schedule', 'Contact']);

    foreach (Konversionsereignis::ERLAUBT as $name) {
        expect(new Konversionsereignis($name, 'e-1', CarbonImmutable::now()))
            ->toBeInstanceOf(Konversionsereignis::class);
    }

    expect(fn () => new Konversionsereignis('Purchase', 'e-1', CarbonImmutable::now()))
        ->toThrow(InvalidArgumentException::class);
});

it('schickt E-Mail und Telefon ausschliesslich gehasht und normalisiert hinaus', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $kontakt = Contact::factory()->create([
        'first_name' => 'Annika',
        'last_name' => 'Mueller',
        'email' => '  Annika@Beispiel.DE ',
        'phone' => '+49 151 12345678',
    ]);

    $nutzlast = (new Konversionsereignis('Lead', 'e-1', CarbonImmutable::now(), $kontakt))->toArray();
    $text = (string) json_encode($nutzlast);

    // Hashen ohne Normalisieren ist kein Hashen: Grossschreibung und
    // Leerzeichen ergeben einen anderen Wert, und Meta ordnet nichts zu.
    expect($nutzlast['user_data']['em'][0])->toBe(hash('sha256', 'annika@beispiel.de'))
        ->and($nutzlast['user_data']['ph'][0])->toBe(hash('sha256', '4915112345678'))
        ->and($text)->not->toContain('Annika')
        ->and($text)->not->toContain('Beispiel')
        ->and($text)->not->toContain('15112345678');
});

it('kennt kein custom_data und keine Wertuebermittlung', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $nutzlast = (new Konversionsereignis('Lead', 'e-1', CarbonImmutable::now()))->toArray();

    // Nicht weil gerade nichts hineingehoert, sondern damit nie etwas
    // hineinkommt: ein leeres Feld fuellt sich.
    expect($nutzlast)->not->toHaveKey('custom_data')
        ->and($nutzlast)->not->toHaveKey('value')
        ->and($nutzlast)->not->toHaveKey('currency')
        ->and(array_keys($nutzlast))->toBe(['event_name', 'event_id', 'event_time', 'action_source', 'user_data']);
});

it('haelt jeden ausgehenden Payload gegen alle aktiven Katalognamen', function (): void {
    // **Testfall 7 und Regel 2 in ausfuehrbarer Form** -- und zwar im
    // Produktionsweg, nicht nur im Test: ein Test schuetzt vor dem, woran
    // jemand gedacht hat; diese Pruefung auch vor dem Feld, das eine spaetere
    // Fassung hinzufuegt.
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $praxis->settings = ['tracking' => ['meta_pixel_id' => '123456789012345']];
    $praxis->save();

    config()->set('mrs.meta.capi_token', 'geheim');

    Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);

    Http::fake(['graph.test/*' => Http::response(['events_received' => 1])]);

    // Der gewoehnliche Fall geht hinaus.
    app(Conversionsversand::class)->sende($praxis, new Konversionsereignis('Lead', 'e-1', CarbonImmutable::now()));

    Http::assertSentCount(1);

    // Und die Gegenprobe: ein Katalogname im Payload haelt alles an.
    $mitBehandlung = new Konversionsereignis('Lead', 'e-Botox-1', CarbonImmutable::now());

    expect(fn () => app(Conversionsversand::class)->sende($praxis, $mitBehandlung))
        ->toThrow(RuntimeException::class, 'Botox');

    Http::assertSentCount(1);
});

it('sendet ohne Zugang gar nicht', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $praxis->settings = ['tracking' => ['meta_pixel_id' => '123456789012345']];
    $praxis->save();

    config()->set('mrs.meta.capi_token', null);

    Http::fake();

    app(Conversionsversand::class)->sende($praxis, new Konversionsereignis('Lead', 'e-1', CarbonImmutable::now()));

    // Der Normalfall vor dem App Review -- und kein Fehler.
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Regeln
|--------------------------------------------------------------------------
*/

it('zeigt keiner Praxis die Zahlen einer anderen', function (): void {
    testkampagne();

    $andere = alsMandant(organisation('Praxis B'));

    expect(app(Auswertung::class)->zeilen(CarbonImmutable::now()->subDays(30), CarbonImmutable::now()))
        ->toBeEmpty();

    actingAs(User::factory()->fuer($andere, Role::Owner)->create())
        ->get(route('auswertung.index'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->where('zeilen', [])->where('summe.leads', 0));
});

it('laesst niemanden ohne insights.view an die Auswertung', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    actingAs(User::factory()->fuer($organisation, Role::Reception)->create())
        ->get(route('auswertung.index'))
        ->assertForbidden();
});
