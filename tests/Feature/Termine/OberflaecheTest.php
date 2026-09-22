<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Contact;
use App\Models\User;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| Die Vorschlagsliste kommt ueber einen Inertia-Teilnachladevorgang
|--------------------------------------------------------------------------
|
| Entscheidung S2 schliesst eine eigene API-Schicht fuer das eigene Frontend
| aus. Die Slots kommen deshalb als 'optional'-Prop derselben Seite und werden
| nur berechnet, wenn sie angefordert werden. Genau das prueft dieser Test --
| beides: dass sie kommen, und dass sie sonst nicht kommen.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * @param  array<string, string|null>  $daten
 * @return TestResponse<Response>
 */
function teilAbruf(User $benutzer, string $prop, array $daten): TestResponse
{
    return actingAs($benutzer)->get(
        route('appointments.index', $daten),
        [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'termine/Index',
            'X-Inertia-Partial-Data' => $prop,
            // Ohne die Version antwortet Inertia mit 409 und einem
            // Neuladehinweis -- das ist kein Fehler, sondern der
            // Aktualisierungsweg nach einem Deployment. Sie kommt aus der
            // Middleware selbst und nicht aus Inertia::getVersion(): dort
            // steht sie erst, nachdem die Middleware einmal gelaufen ist.
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)
                ->version(request()),
        ],
    );
}

it('berechnet die Vorschlaege nur auf Anforderung', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    $ohne = actingAs($benutzer)->get(route('appointments.index', [
        'date' => Szenario::TAG,
        'location' => $szenario->aufbau->standort->uuid,
    ]));

    $ohne->assertInertia(fn ($seite) => $seite->missing('proposals'));

    $mit = teilAbruf($benutzer, 'proposals', [
        'date' => Szenario::TAG,
        'location' => $szenario->aufbau->standort->uuid,
        'type' => (string) $szenario->aufbau->art->uuid,
    ]);

    $mit->assertOk();

    /** @var array<string, mixed> $daten */
    $daten = $mit->json('props');

    expect($daten['proposals'])->not->toBeEmpty()
        // Der Beginn der belegten Strecke geht zurueck an den Server -- nicht
        // die angezeigte Zeit.
        ->and($daten['proposals'][0])->toHaveKeys(['blocked_from', 'local_time', 'practitioner']);
});

it('liefert die Kontaktsuche ueber denselben Weg', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;

    Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller', 'email' => 'annika@example.test']);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    $antwort = teilAbruf($benutzer, 'contacts', [
        'date' => Szenario::TAG,
        'location' => $szenario->aufbau->standort->uuid,
        'search' => 'mueller',
    ]);

    expect($antwort->json('props.contacts'))->toHaveCount(1)
        ->and($antwort->json('props.contacts.0.name'))->toBe('Annika Mueller');
});

it('liefert ohne Terminart keine Vorschlaege', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    $antwort = teilAbruf($benutzer, 'proposals', [
        'date' => Szenario::TAG,
        'location' => $szenario->aufbau->standort->uuid,
    ]);

    expect($antwort->json('props.proposals'))->toBe([]);
});

it('verschiebt einen Termin ueber die Oberflaeche', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;

    $frueh = $szenario->vorschlag(0);
    $spaet = $szenario->vorschlag(20);

    $termin = app(Terminplaner::class)->buche(
        $frueh,
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->patch(route('appointments.reschedule', ['appointment' => $termin->uuid]), [
            'practitioner' => $szenario->aufbau->behandler->uuid,
            'location' => $szenario->aufbau->standort->uuid,
            'blocked_from' => $spaet->blockedFrom->toIso8601String(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($termin->fresh()?->starts_at->equalTo($spaet->startsAt))->toBeTrue();
});

it('setzt den Status und sagt ab ueber die Oberflaeche', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;

    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    // Vor Terminbeginn ist "Erschienen" kein zulaessiger Wechsel -- und die
    // Meldung gehoert ans Formular, nicht auf eine Ausnahmeseite.
    actingAs($benutzer)
        ->patch(route('appointments.status', ['appointment' => $termin->uuid]), ['status' => 'attended'])
        ->assertSessionHasErrors('blocked_from');

    actingAs($benutzer)
        ->delete(route('appointments.cancel', ['appointment' => $termin->uuid]), ['reason' => 'contact'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($termin->fresh()?->status->value)->toBe('cancelled')
        ->and($termin->slots()->count())->toBe(0);
});

it('bietet keinen Statuswechsel an, den der Server ablehnen wuerde', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;

    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    $seite = fn (): TestResponse => teilAbruf($benutzer, 'appointments', [
        'date' => Szenario::TAG,
        'location' => $szenario->aufbau->standort->uuid,
    ]);

    // Vor dem Termin: nur "Angefragt" waere moeglich -- und der Termin ist
    // schon bestaetigt. Also bleibt nichts.
    expect($seite()->json('props.appointments.0.next_statuses'))->toBe([]);

    travelTo($termin->starts_at->addHour());

    /** @var list<array{value: string, label: string}> $moeglich */
    $moeglich = $seite()->json('props.appointments.0.next_statuses');
    $spaeter = array_column($moeglich, 'value');

    expect($spaeter)->toEqualCanonicalizing(['attended', 'no_show']);
});
