<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\HoldPurpose;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\KonversionMelden;
use App\Models\Appointment;
use App\Models\AttributionTouch;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Practitioner;
use App\Models\SlotHold;
use App\Models\Treatment;
use App\Tenancy\TenantContext;
use App\Verfuegbarkeit\SlotHalter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;
use function Pest\Laravel\withCookies;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-12, Abnahmekriterien 1 bis 20
|--------------------------------------------------------------------------
|
| Die einzigen Routen des Produkts ohne Anmeldung. Der Mandant kommt aus dem
| Slug und **nur** von dort.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Eine Praxis mit oeffentlich buchbarer Terminart und erzeugten Slots.
 *
 * @return array{Organization, Szenario}
 */
function praxisMitBuchungsseite(string $slug = 'demo-praxis'): array
{
    $organisation = alsMandant(Organization::factory()->create(['name' => 'Praxis', 'slug' => $slug]));
    $szenario = new Szenario;

    $szenario->aufbau->art->is_public = true;
    $szenario->aufbau->art->save();

    return [$organisation, $szenario];
}

/** @return array<string, string> */
function reservierungsdaten(Szenario $szenario): array
{
    $vorschlag = $szenario->vorschlag();

    return [
        'appointment_type' => (string) $szenario->aufbau->art->uuid,
        'location' => (string) $szenario->aufbau->standort->uuid,
        'practitioner' => (string) $szenario->aufbau->behandler->uuid,
        'blocked_from' => $vorschlag->blockedFrom->toIso8601String(),
    ];
}

/** @return array<string, mixed> */
function kontaktdaten(): array
{
    return [
        'first_name' => 'Annika',
        'last_name' => 'Mueller',
        'email' => 'annika@example.test',
        'phone' => '+49 170 1234567',
        'consent' => true,
    ];
}

/* Mandantengrenze ---------------------------------------------------------- */

it('antwortet auf einen unbekannten Slug mit 404', function (): void {
    praxisMitBuchungsseite();

    get('/buchen/gibt-es-nicht')->assertNotFound();
});

it('antwortet fuer eine gesperrte Praxis mit 404', function (): void {
    [$organisation] = praxisMitBuchungsseite();

    $organisation->suspended_at = CarbonImmutable::now();
    $organisation->save();

    get('/buchen/demo-praxis')->assertNotFound();
});

it('zeigt ausschliesslich Daten dieser Praxis', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    // Eine zweite Praxis mit eigener oeffentlicher Terminart.
    $fremde = alsMandant(Organization::factory()->create(['name' => 'Andere', 'slug' => 'andere-praxis']));
    $fremdesSzenario = new Szenario;
    $fremdesSzenario->aufbau->art->is_public = true;
    $fremdesSzenario->aufbau->art->save();

    app(TenantContext::class)->forget();

    get('/buchen/demo-praxis')
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->where('practice.slug', 'demo-praxis')
            ->has('appointmentTypes', 1)
            ->where('appointmentTypes.0.uuid', $szenario->aufbau->art->uuid)
            ->has('locations', 1)
        );

    expect($fremde->slug)->toBe('andere-praxis');
});

it('bucht keine Terminart einer fremden Praxis', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    $fremde = alsMandant(Organization::factory()->create(['name' => 'Andere', 'slug' => 'andere-praxis']));
    $fremdesSzenario = new Szenario;
    $fremdesSzenario->aufbau->art->is_public = true;
    $fremdesSzenario->aufbau->art->save();

    // Die Daten **vor** dem Vergessen bauen: die Testhilfe fragt die
    // Verfuegbarkeit ab und braucht dafuer einen Mandanten.
    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());
    $daten = [
        ...reservierungsdaten($szenario),
        'appointment_type' => (string) $fremdesSzenario->aufbau->art->uuid,
    ];

    app(TenantContext::class)->forget();

    // Gueltige UUID -- nur eben aus der falschen Praxis.
    post('/buchen/demo-praxis/reservieren', $daten)->assertNotFound();

    expect($fremde->slug)->toBe('andere-praxis');
});

it('loest keine Reservierung einer fremden Sitzung ein', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    // Jemand anderes haelt den Slot -- unsere Sitzung weiss nichts davon.
    app(SlotHalter::class)->halte($szenario->vorschlag(), HoldPurpose::PublicBooking);

    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis', kontaktdaten())
        ->assertSessionHasErrors('blocked_from');

    expect(Appointment::query()->count())->toBe(0);
});

/* Sichtbarkeit ------------------------------------------------------------- */

it('zeigt keine nicht oeffentliche Terminart', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    $szenario->aufbau->art->is_public = false;
    $szenario->aufbau->art->save();

    app(TenantContext::class)->forget();

    get('/buchen/demo-praxis')->assertInertia(fn ($seite) => $seite->has('appointmentTypes', 0));
});

it('zeigt keine inaktive Terminart', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    $szenario->aufbau->art->is_active = false;
    $szenario->aufbau->art->save();

    app(TenantContext::class)->forget();

    get('/buchen/demo-praxis')->assertInertia(fn ($seite) => $seite->has('appointmentTypes', 0));
});

it('zeigt keinen inaktiven Standort', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    $szenario->aufbau->standort->is_active = false;
    $szenario->aufbau->standort->save();

    app(TenantContext::class)->forget();

    get('/buchen/demo-praxis')->assertInertia(fn ($seite) => $seite->has('locations', 0));
});

it('zeigt weder ungeprueften Preis noch ungepruefte Beschreibung', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    $behandlung = Treatment::factory()->create([
        'description' => 'Strafft die Stirn dauerhaft.',
        'price_from_cents' => 25000,
    ]);

    $szenario->aufbau->art->description = 'Strafft die Stirn dauerhaft.';
    $szenario->aufbau->art->treatment_id = $behandlung->getKey();
    $szenario->aufbau->art->save();

    app(TenantContext::class)->forget();

    // Beides steht im Katalog und erscheint erst nach der HWG-Pruefung
    // (WP-30, tests/Feature/Compliance/VeroeffentlichungTest.php). Eine
    // ungepruefte Wirkaussage auf der Werbeseite einer aesthetischen Praxis
    // ist genau die Haftung, gegen die dieses Produkt antritt. Die
    // Beschreibung der Terminart ist intern und erscheint nie.
    $antwort = get('/buchen/demo-praxis');

    $antwort->assertInertia(fn ($seite) => $seite
        ->has('appointmentTypes.0')
        ->where('appointmentTypes.0.description', null)
        ->where('appointmentTypes.0.price', null)
        ->missing('appointmentTypes.0.price_from_cents')
    );
});

/* Anzeigeraster ------------------------------------------------------------ */

it('bietet nur Startzeiten auf dem Anzeigeraster an', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    app(TenantContext::class)->forget();

    $tage = teilAbrufTage($szenario);

    expect($tage)->not->toBeEmpty();

    foreach ($tage as $tag) {
        foreach ($tag['slots'] as $slot) {
            expect((int) substr($slot['time'], 3, 2) % 15)->toBe(0);
        }
    }
});

it('rastert die angezeigte Zeit, nicht die belegte', function (): void {
    // Arbeitsbeginn 09:00, fuenf Minuten Ruestzeit davor. Die frueheste
    // belegte Strecke beginnt also 09:00 und zeigt 09:05 -- das liegt nicht
    // auf dem 15-Minuten-Raster. Der erste angebotene Termin ist 09:15, und
    // seine belegte Strecke beginnt 09:10.
    [, $szenario] = praxisMitBuchungsseiteMitRuestzeit();

    app(TenantContext::class)->forget();

    $tage = teilAbrufTage($szenario);
    $ersterSlot = $tage[0]['slots'][0];

    // Genau das ist der Punkt: die **angezeigte** Zeit liegt auf dem Raster,
    // die belegte nicht. Wer auf blocked_from rastert, bietet 09:05, 09:20,
    // 09:35 an.
    expect($ersterSlot['time'])->toBe('09:15')
        ->and(CarbonImmutable::parse($ersterSlot['blocked_from'])->setTimezone('Europe/Berlin')->format('H:i'))
        ->toBe('09:10');
});

/* Reservieren und buchen --------------------------------------------------- */

it('haelt den Slot, bevor das Formular erscheint', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    app(TenantContext::class)->forget();

    get('/buchen/demo-praxis')->assertInertia(fn ($seite) => $seite->where('hold', null));

    post('/buchen/demo-praxis/reservieren', reservierungsdaten($szenario))->assertRedirect();

    get('/buchen/demo-praxis')->assertInertia(fn ($seite) => $seite
        ->has('hold.starts_at')
        ->has('hold.expires_at')
    );

    expect(SlotHold::query()->gueltig()->count())->toBe(1);
});

it('macht den gehaltenen Slot fuer andere unsichtbar', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    $vorher = count(teilAbrufTage($szenario)[0]['slots']);

    post('/buchen/demo-praxis/reservieren', $daten)->assertRedirect();

    $nachher = count(teilAbrufTage($szenario)[0]['slots']);

    expect($nachher)->toBeLessThan($vorher);
});

it('erzeugt eine Buchung im Status pending mit Kanal public', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis/reservieren', $daten);
    post('/buchen/demo-praxis', kontaktdaten())
        ->assertRedirect(route('buchung.bestaetigt', ['praxis' => 'demo-praxis']))
        ->assertSessionHasNoErrors();

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    $termin = Appointment::query()->firstOrFail();

    expect($termin->status)->toBe(AppointmentStatus::Pending)
        ->and($termin->booked_via)->toBe(BookingChannel::Public)
        ->and($termin->consent_accepted_at)->not->toBeNull();
});

it('behaelt die Zeilen der Reservierung', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis/reservieren', $daten);

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());
    $gehalten = SlotHold::query()->firstOrFail()->slots()->orderBy('starts_at')->pluck('id')->all();
    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis', kontaktdaten());

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());
    $termin = Appointment::query()->firstOrFail();

    expect($termin->slots()->orderBy('starts_at')->pluck('id')->all())->toBe($gehalten);
});

it('loest einen abgelaufenen Hold nicht mehr ein', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis/reservieren', $daten);

    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC')->addMinutes(11));

    post('/buchen/demo-praxis', kontaktdaten())->assertSessionHasErrors('blocked_from');

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    expect(Appointment::query()->count())->toBe(0);
});

it('gibt die Reservierung wieder frei', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis/reservieren', $daten);
    delete('/buchen/demo-praxis/reservieren')->assertRedirect();

    get('/buchen/demo-praxis')->assertInertia(fn ($seite) => $seite->where('hold', null));

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    expect(SlotHold::query()->gueltig()->count())->toBe(0);
});

it('bucht nicht ohne Einwilligung', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis/reservieren', $daten);
    post('/buchen/demo-praxis', [...kontaktdaten(), 'consent' => false])
        ->assertSessionHasErrors('consent');

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    expect(Appointment::query()->count())->toBe(0);
});

it('erkennt einen bestehenden Kontakt an der E-Mail-Adresse', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller', 'email' => 'annika@example.test']);

    $vorher = Contact::query()->count();

    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis/reservieren', $daten);
    post('/buchen/demo-praxis', kontaktdaten());

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    // Entscheidung D6: automatisches Zusammenfuehren nur bei identischer
    // E-Mail. Dieselbe Regel gilt beim Anlegen -- die Buchung legt keinen
    // zweiten Datensatz an.
    expect(Contact::query()->count())->toBe($vorher)
        ->and(Appointment::query()->firstOrFail()->contact->email)->toBe('annika@example.test');
});

/* Missbrauch --------------------------------------------------------------- */

it('faengt eine automatisierte Buchung im Honigtopf', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis/reservieren', $daten);

    // Ein Feld, das kein Mensch sieht und kein Mensch ausfuellt.
    post('/buchen/demo-praxis', [...kontaktdaten(), 'website' => 'https://spam.example'])
        ->assertSessionHasErrors('website');

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    expect(Appointment::query()->count())->toBe(0);
});

/* Hilfen ------------------------------------------------------------------- */

/** @return array{Organization, Szenario} */
function praxisMitBuchungsseiteMitRuestzeit(): array
{
    $organisation = alsMandant(Organization::factory()->create(['name' => 'Praxis', 'slug' => 'demo-praxis']));
    $szenario = new Szenario(von: '09:00:00', bis: '17:00:00', dauer: 30, ruestzeitDavor: 5);

    $szenario->aufbau->art->is_public = true;
    $szenario->aufbau->art->save();

    return [$organisation, $szenario];
}

/**
 * Die Tagesliste kommt als Inertia-Teilnachladevorgang -- wie in WP-11.
 *
 * @return list<array{date: string, weekday: string, slots: list<array<string, mixed>>}>
 */
function teilAbrufTage(Szenario $szenario): array
{
    $antwort = get(
        '/buchen/demo-praxis?'.http_build_query([
            'type' => $szenario->aufbau->art->uuid,
            'location' => $szenario->aufbau->standort->uuid,
        ]),
        [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'buchung/Index',
            'X-Inertia-Partial-Data' => 'days',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ],
    );

    /** @var list<array{date: string, weekday: string, slots: list<array<string, mixed>>}> */
    return $antwort->json('props.days') ?? [];
}

it('bietet jede Uhrzeit nur einmal an', function (): void {
    [, $szenario] = praxisMitBuchungsseite();

    // Ein zweiter Behandler mit denselben Arbeitszeiten: die Engine liefert
    // damit jeden Zeitpunkt doppelt.
    $zweiter = Practitioner::factory()->create();
    $zweiter->locations()->attach($szenario->aufbau->standort);
    $szenario->aufbau->art->practitioners()->attach($zweiter);

    foreach ($szenario->aufbau->behandler->workingHours as $zeit) {
        $zweiter->workingHours()->create([
            'location_id' => $zeit->location_id,
            'weekday' => $zeit->weekday,
            'starts_at' => $zeit->starts_at,
            'ends_at' => $zeit->ends_at,
        ]);
    }

    $szenario->aufbau->erzeugeSlots(Szenario::TAG, Szenario::TAG);

    app(TenantContext::class)->forget();

    $tage = teilAbrufTage($szenario);

    expect($tage)->not->toBeEmpty();

    foreach ($tage as $tag) {
        $zeiten = array_column($tag['slots'], 'time');

        // Fuer die Interessentin sind zwei Behandler zur selben Uhrzeit nicht
        // zwei Angebote, sondern dieselbe Uhrzeit doppelt.
        expect($zeiten)->toBe(array_values(array_unique($zeiten)));
    }
});

/*
|--------------------------------------------------------------------------
| Die Kette, von Ende zu Ende
|--------------------------------------------------------------------------
*/

it('traegt die Kampagne vom Klick bis in den Snapshot des Termins', function (): void {
    // **Testfall 1** aus docs/fachlogik/attribution.md -- der Grund, warum es
    // dieses Produkt gibt.
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    $cookies = [
        (string) config('mrs.attribution.consent_cookie') => 'ja',
        (string) config('mrs.attribution.visitor_cookie_name') => 'besucher-kette',
    ];

    // Klick auf die Anzeige.
    withCookies($cookies)
        ->get('/buchen/demo-praxis?fbclid=klick-1&utm_source=facebook&mrs_campaign=camp-1')
        ->assertOk();

    withCookies($cookies)->post('/buchen/demo-praxis/reservieren', $daten);
    withCookies($cookies)->post('/buchen/demo-praxis', kontaktdaten());

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    $termin = Appointment::query()->firstOrFail();
    $stand = json_decode((string) $termin->attribution_snapshot, true);

    expect($stand)->toBeArray()
        ->and($stand['kampagne'])->toBe('camp-1')
        ->and($stand['klick'])->toBe('klick-1')
        ->and($stand['utm_source'])->toBe('facebook')
        // Und der Touch hängt jetzt am Kontakt.
        ->and(AttributionTouch::query()->whereNotNull('contact_id')->count())->toBe(1)
        // Die Kennung liegt offen daneben, damit sich gruppieren laesst
        // (WP-32b) -- sie ist eine Ziffernfolge ohne Aussage.
        ->and($termin->attribution_campaign_id)->toBe('camp-1');
});

it('nutzt fuer Pixel und Serverereignis dieselbe Kennung', function (): void {
    // **Testfall 8.** Ohne sie zaehlt Meta doppelt: Pixel und Conversions API
    // melden dasselbe Ereignis. Abgeleitet statt gespeichert -- beide Seiten
    // kommen unabhaengig voneinander auf denselben Wert.
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    Queue::fake();
    app(TenantContext::class)->forget();

    $cookies = [
        (string) config('mrs.attribution.consent_cookie') => 'ja',
        (string) config('mrs.attribution.visitor_cookie_name') => 'besucher-kennung',
    ];

    withCookies($cookies)->get('/buchen/demo-praxis?fbclid=klick-1');
    withCookies($cookies)->post('/buchen/demo-praxis/reservieren', $daten);
    withCookies($cookies)->post('/buchen/demo-praxis', kontaktdaten());

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    $termin = Appointment::query()->firstOrFail();
    $erwartet = 'lead-'.$termin->uuid;

    ohneMandant();

    // Die Seite gibt sie ans Pixel ...
    withCookies($cookies)
        ->withSession(['buchung' => (string) $termin->uuid])
        ->get(route('buchung.bestaetigt', ['praxis' => 'demo-praxis']))
        ->assertInertia(fn ($seite) => $seite->where('leadEventId', $erwartet));

    // ... und der Auftrag traegt dieselbe.
    Queue::assertPushed(
        KonversionMelden::class,
        fn (KonversionMelden $auftrag): bool => $auftrag->uniqueId() === $erwartet,
    );
});

it('meldet ohne Einwilligung kein Ereignis an Meta', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    Queue::fake();
    app(TenantContext::class)->forget();

    post('/buchen/demo-praxis/reservieren', $daten);
    post('/buchen/demo-praxis', kontaktdaten());

    Queue::assertNotPushed(KonversionMelden::class);
});

it('schreibt ohne Einwilligung auch beim Buchen nichts', function (): void {
    [, $szenario] = praxisMitBuchungsseite();
    $daten = reservierungsdaten($szenario);

    app(TenantContext::class)->forget();

    get('/buchen/demo-praxis?fbclid=klick-1');
    post('/buchen/demo-praxis/reservieren', $daten);
    post('/buchen/demo-praxis', kontaktdaten());

    alsMandant(Organization::query()->where('slug', 'demo-praxis')->firstOrFail());

    expect(AttributionTouch::query()->count())->toBe(0)
        // Gebucht wird trotzdem: die Seite bleibt vollstaendig benutzbar.
        ->and(Appointment::query()->count())->toBe(1)
        ->and(Appointment::query()->first()?->attribution_snapshot)->toBeNull();
});
