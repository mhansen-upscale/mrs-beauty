<?php

declare(strict_types=1);

use App\Enums\CancellationReason;
use App\Enums\LeadSource;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Practitioner;
use App\Models\User;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| Die Uhr steht auf dem Vortag des Szenarios
|--------------------------------------------------------------------------
|
| Der Controller kennt kein "jetzt" von aussen -- er fragt die Uhr. Ohne
| dieses travelTo laege der Testtag jenseits des Buchungshorizonts von
| 90 Tagen, und die Buchung scheiterte an V10 statt am Geprueften.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/*
|--------------------------------------------------------------------------
| WP-11, Abnahmekriterien 34 und 35 -- Zugang
|--------------------------------------------------------------------------
*/

it('laesst ohne appointments.manage keinen Termin aendern', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;

    $termin = app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    // Marketing darf Kampagnen und Auswertung, keine Termine.
    $benutzer = User::factory()->fuer($organisation, Role::Marketing)->create();

    actingAs($benutzer)
        ->delete(route('appointments.cancel', ['appointment' => $termin->uuid]), [
            'reason' => CancellationReason::Contact->value,
        ])
        ->assertForbidden();

    expect($termin->fresh()?->status->value)->toBe('confirmed');
});

it('zeigt einer Behandlerin nur ihren eigenen Kalender', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $planer = app(Terminplaner::class);

    $eigener = $planer->buche($szenario->vorschlag(0), $szenario->kontakt, jetzt: $szenario->jetzt());

    // Eine zweite Behandlerin mit eigenem Termin am selben Tag.
    $zweite = Practitioner::factory()->create();
    $zweite->locations()->attach($szenario->aufbau->standort);
    $szenario->aufbau->art->practitioners()->attach($zweite);

    foreach ($szenario->aufbau->behandler->workingHours as $zeit) {
        $zweite->workingHours()->create([
            'location_id' => $zeit->location_id,
            'weekday' => $zeit->weekday,
            'starts_at' => $zeit->starts_at,
            'ends_at' => $zeit->ends_at,
        ]);
    }

    $szenario->aufbau->erzeugeSlots(Szenario::TAG, Szenario::TAG);

    $fremder = $planer->buche(
        Slotvorschlag::ab(
            $szenario->aufbau->art,
            $zweite,
            $szenario->aufbau->standort,
            $szenario->vorschlag(0)->blockedFrom,
        ),
        $szenario->kontakt,
        jetzt: $szenario->jetzt(),
    );

    $benutzer = User::factory()->fuer($organisation, Role::Practitioner)->create();
    $szenario->aufbau->behandler->user_id = $benutzer->getKey();
    $szenario->aufbau->behandler->save();

    actingAs($benutzer)
        ->get(route('appointments.index', [
            'date' => Szenario::TAG,
            'location' => $szenario->aufbau->standort->uuid,
        ]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->where('canManage', false)
            ->has('practitioners', 1)
            ->has('appointments', 1)
            ->where('appointments.0.uuid', $eigener->uuid)
        );

    expect($fremder->practitioner_id)->toBe($zweite->getKey());
});

it('zeigt dem Empfang alle Behandler des Standorts', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;

    app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, jetzt: $szenario->jetzt());

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('appointments.index', [
            'date' => Szenario::TAG,
            'location' => $szenario->aufbau->standort->uuid,
        ]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->where('canManage', true)
            ->has('appointments', 1)
        );
});

it('bucht ueber die Oberflaeche und legt den Kontakt dabei an', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->post(route('appointments.store'), [
            'appointment_type' => $szenario->aufbau->art->uuid,
            'practitioner' => $szenario->aufbau->behandler->uuid,
            'location' => $szenario->aufbau->standort->uuid,
            'blocked_from' => $vorschlag->blockedFrom->toIso8601String(),
            'first_name' => 'Annika',
            'last_name' => 'Mueller',
            'email' => 'annika@example.test',
            'quelle' => LeadSource::Phone->value,
        ])
        ->assertRedirect();

    expect(Appointment::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(2);
});

it('meldet einen belegten Zeitraum als Formularfehler', function (): void {
    $organisation = alsMandant();
    $szenario = new Szenario;
    $vorschlag = $szenario->vorschlag();

    app(Terminplaner::class)->buche($vorschlag, $szenario->kontakt, jetzt: $szenario->jetzt());

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->post(route('appointments.store'), [
            'appointment_type' => $szenario->aufbau->art->uuid,
            'practitioner' => $szenario->aufbau->behandler->uuid,
            'location' => $szenario->aufbau->standort->uuid,
            'blocked_from' => $vorschlag->blockedFrom->toIso8601String(),
            'contact' => $szenario->kontakt->uuid,
            'quelle' => LeadSource::Phone->value,
        ])
        // Keine Ausnahmeseite: die Meldung gehoert ans Formularfeld.
        ->assertSessionHasErrors('blocked_from');

    expect(session('errors')?->first('blocked_from'))->toContain('vergeben');
});
