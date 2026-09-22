<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Kontakte\Kontaktsuche;
use App\Kontakte\Nichtzusammenfuehrbar;
use App\Kontakte\Zusammenfuehrung;
use App\Models\Appointment;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\ContactMerge;
use App\Tenancy\TenantContext;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-16, Abnahmekriterien 10 bis 19 -- Zusammenführen (D6, D7, A12, D10)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

it('fuehrt bei identischer E-Mail automatisch zusammen', function (): void {
    $erster = Contact::create(['first_name' => 'Anna', 'last_name' => 'Falk', 'email' => 'anna@praxis.test']);

    $zweiter = app(Kontaktsuche::class)->findeOderLege([
        'first_name' => 'Anna',
        'last_name' => 'Falk-Meier',
        'email' => 'anna@praxis.test',
    ]);

    expect($zweiter->getKey())->toBe($erster->getKey())
        ->and(Contact::query()->count())->toBe(1);
});

it('fuehrt bei identischer Telefonnummer zusammen, auch in anderer Schreibweise', function (): void {
    $erster = Contact::create(['first_name' => 'Bea', 'last_name' => 'Winter', 'phone' => '+49 170 1234567']);

    $zweiter = app(Kontaktsuche::class)->findeOderLege([
        'first_name' => 'Bea',
        'last_name' => 'Winter',
        'phone' => '0170 1234567',
    ]);

    expect($zweiter->getKey())->toBe($erster->getKey())
        ->and(Contact::query()->count())->toBe(1);
});

it('fuehrt bei gleichem Namen allein nicht zusammen, sondern schlaegt vor', function (): void {
    // Zwei Datensaetze derselben Person sind reparierbar. Zwei Personen in
    // einem Datensatz nicht -- in einer aesthetischen Praxis heisst das, dass
    // jemand die Termine eines anderen sieht.
    Contact::create(['first_name' => 'Chris', 'last_name' => 'Meier', 'email' => 'chris1@praxis.test']);

    $zweiter = app(Kontaktsuche::class)->findeOderLege([
        'first_name' => 'Chris',
        'last_name' => 'Meier',
        'email' => 'chris2@praxis.test',
    ]);

    expect(Contact::query()->count())->toBe(2)
        ->and($zweiter->email)->toBe('chris2@praxis.test');

    $vorschlaege = app(Zusammenfuehrung::class)->vorschlaege();

    expect($vorschlaege)->toHaveCount(1)
        ->and($vorschlaege[0][0]->name())->toBe('Chris Meier');
});

it('veraendert durch einen Vorschlag nichts', function (): void {
    Contact::create(['first_name' => 'Dana', 'last_name' => 'Groth', 'email' => 'd1@praxis.test']);
    Contact::create(['first_name' => 'Dana', 'last_name' => 'Groth', 'email' => 'd2@praxis.test']);

    app(Zusammenfuehrung::class)->vorschlaege();

    expect(Contact::query()->count())->toBe(2)
        ->and(ContactMerge::query()->count())->toBe(0);
});

it('haengt Termine und Kanaele nach dem Zusammenfuehren am Gewinner', function (): void {
    $szenario = new Szenario;

    // Ohne Nummer, damit sichtbar wird, dass leere Felder ergaenzt werden.
    $gewinner = $szenario->kontakt;
    $gewinner->phone = null;
    $gewinner->save();

    $verlierer = Contact::create(['first_name' => 'Doppel', 'last_name' => 'Gaenger', 'phone' => '+49 170 9999999']);
    $verlierer->channelIdentities()->create(['channel' => ChannelType::Instagram, 'external_id' => 'ig-4711']);

    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $verlierer,
        jetzt: $szenario->jetzt(),
    );

    app(Zusammenfuehrung::class)->fuehreZusammen($gewinner, $verlierer);

    expect($termin->fresh()?->contact_id)->toBe($gewinner->getKey())
        ->and(ChannelIdentity::query()->first()?->contact_id)->toBe($gewinner->getKey())
        // Leere Felder des Gewinners werden ergaenzt.
        ->and($gewinner->fresh()?->phone)->toBe('+49 170 9999999');
});

it('loescht den Verlierer wirklich', function (): void {
    // Entscheidung A12: ein Soft Delete waere genau die Hintertuer, die eine
    // Loeschanfrage nach DSGVO unwirksam macht.
    $gewinner = Contact::create(['first_name' => 'Eva', 'last_name' => 'Stark']);
    $verlierer = Contact::create(['first_name' => 'Eva', 'last_name' => 'Stark']);

    app(Zusammenfuehrung::class)->fuehreZusammen($gewinner, $verlierer);

    expect(Contact::query()->count())->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(1);
});

it('legt den Snapshot verschluesselt ab', function (): void {
    $gewinner = Contact::create(['first_name' => 'Frank', 'last_name' => 'Ohlsen']);
    $verlierer = Contact::create(['first_name' => 'Franziska', 'last_name' => 'Ohlsen-Uniquename']);

    app(Zusammenfuehrung::class)->fuehreZusammen($gewinner, $verlierer);

    $treffer = [];

    foreach (DB::select('show tables') as $zeile) {
        $tabelle = (string) array_values((array) $zeile)[0];

        foreach (DB::table($tabelle)->get() as $datensatz) {
            foreach ((array) $datensatz as $spalte => $wert) {
                if (is_string($wert) && str_contains($wert, 'Uniquename')) {
                    $treffer[] = "{$tabelle}.{$spalte}";
                }
            }
        }
    }

    expect($treffer)->toBeEmpty('Der Snapshot steht im Klartext: '.implode(', ', $treffer))
        ->and(ContactMerge::query()->firstOrFail()->inhalt()['kontakt']['last_name'])
        ->toBe('Ohlsen-Uniquename');
});

it('stellt den Kontakt und seine Zuordnungen wieder her', function (): void {
    $szenario = new Szenario;

    $gewinner = $szenario->kontakt;
    $gewinner->phone = null;
    $gewinner->save();

    $verlierer = Contact::create(['first_name' => 'Gerd', 'last_name' => 'Halm', 'phone' => '+49 170 8888888']);
    $verlierer->channelIdentities()->create(['channel' => ChannelType::Instagram, 'external_id' => 'ig-8888']);

    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $verlierer, jetzt: $szenario->jetzt());

    $vorgang = app(Zusammenfuehrung::class)->fuehreZusammen($gewinner, $verlierer);

    $wieder = app(Zusammenfuehrung::class)->macheRueckgaengig($vorgang);

    expect($wieder->getKey())->toBe($verlierer->getKey())
        ->and($wieder->last_name)->toBe('Halm')
        ->and($termin->fresh()?->contact_id)->toBe($wieder->getKey())
        ->and(ChannelIdentity::query()->first()?->contact_id)->toBe($wieder->getKey())
        // Was am Gewinner ergaenzt wurde, gehoerte ihm nie.
        ->and($gewinner->fresh()?->phone)->toBeNull()
        // Der Snapshot hat seinen Zweck erfuellt und bleibt nicht liegen.
        ->and($vorgang->fresh()?->snapshot)->toBeNull()
        ->and($vorgang->fresh()?->reverted_at)->not->toBeNull();
});

it('macht nach Ablauf des Snapshots nichts mehr rueckgaengig', function (): void {
    $gewinner = Contact::create(['first_name' => 'Hanna', 'last_name' => 'Ruf']);
    $verlierer = Contact::create(['first_name' => 'Hanna', 'last_name' => 'Ruf']);

    $vorgang = app(Zusammenfuehrung::class)->fuehreZusammen($gewinner, $verlierer);

    $spaeter = CarbonImmutable::now()->addDays(31);

    expect($vorgang->istUmkehrbar($spaeter))->toBeFalse()
        ->and(fn () => app(Zusammenfuehrung::class)->macheRueckgaengig($vorgang, $spaeter))
        ->toThrow(Nichtzusammenfuehrbar::class)
        // Der Vorgang bleibt sichtbar, auch wenn er nicht mehr umkehrbar ist.
        ->and(ContactMerge::query()->count())->toBe(1);
});

it('fuehrt einen Kontakt nicht mit sich selbst zusammen', function (): void {
    $kontakt = Contact::create(['first_name' => 'Ina', 'last_name' => 'Selbst']);

    expect(fn () => app(Zusammenfuehrung::class)->fuehreZusammen($kontakt, $kontakt))
        ->toThrow(Nichtzusammenfuehrbar::class);
});

it('fuehrt ueber Organisationsgrenzen nicht zusammen', function (): void {
    // Entscheidung D10. Der globale Scope schliesst es bereits aus -- die
    // Pruefung steht trotzdem da, weil ein Merge ueber Mandantengrenzen kein
    // Schoenheitsfehler waere, sondern Regel 1 gebrochen.
    $eigener = Contact::create(['first_name' => 'Jan', 'last_name' => 'Hier']);

    $zweite = organisation('Zweite Praxis');
    $fremder = app(TenantContext::class)->runAs(
        $zweite,
        fn (): Contact => Contact::create(['first_name' => 'Jan', 'last_name' => 'Dort']),
    );

    expect(fn () => app(Zusammenfuehrung::class)->fuehreZusammen($eigener, $fremder))
        ->toThrow(Nichtzusammenfuehrbar::class);
});

it('schlaegt keine Kontakte vor, die schon ein hartes Signal teilen', function (): void {
    // Wer dieselbe E-Mail hat, ist beim Anlegen schon zusammengefuehrt worden.
    Contact::create(['first_name' => 'Karl', 'last_name' => 'Einzeln', 'email' => 'karl@praxis.test']);

    expect(app(Zusammenfuehrung::class)->vorschlaege())->toBeEmpty();
});

it('haelt die Zahl der Vorschlaege in Grenzen', function (): void {
    foreach (range(1, 5) as $nummer) {
        Contact::create(['first_name' => 'Lena', 'last_name' => 'Viel', 'email' => "l{$nummer}@praxis.test"]);
    }

    // Fuenf gleiche Namen ergeben zehn Paare -- die Oberflaeche bekommt nicht
    // alle auf einmal.
    expect(app(Zusammenfuehrung::class)->vorschlaege(hoechstens: 4))->toHaveCount(4);
});

it('legt den Termin nach dem Zusammenfuehren nicht doppelt an', function (): void {
    $szenario = new Szenario;

    $verlierer = Contact::create(['first_name' => 'Mia', 'last_name' => 'Doppelt']);
    app(Terminplaner::class)->buche($szenario->vorschlag(), $verlierer, jetzt: $szenario->jetzt());

    app(Zusammenfuehrung::class)->fuehreZusammen($szenario->kontakt, $verlierer);

    expect(Appointment::query()->count())->toBe(1);
});
