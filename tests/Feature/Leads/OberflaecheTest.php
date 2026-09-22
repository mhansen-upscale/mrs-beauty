<?php

declare(strict_types=1);

use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Leads\Leadverwaltung;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Treatment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-17, Abnahmekriterien 16 bis 20 -- Trichter, Zugang, Mandantengrenze
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** Ein Lead je Status, damit der Trichter etwas zu zaehlen hat. */
function trichterdaten(): void
{
    $behandlung = Treatment::factory()->create();

    foreach (LeadStatus::cases() as $status) {
        $kontakt = Contact::create(['first_name' => 'Trichter', 'last_name' => $status->value]);
        $lead = app(Leadverwaltung::class)->erfasse($kontakt, $behandlung, LeadSource::Message);
        $lead->status = $status;
        $lead->save();
    }
}

it('zeigt den Trichter mit einer Zahl je Stufe', function (): void {
    $organisation = alsMandant();
    trichterdaten();

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('leads.index'))
        ->assertInertia(fn ($seite) => $seite
            ->component('leads/Index')
            ->where('funnel.new', 1)
            ->where('funnel.won', 1)
            ->where('funnel.lost', 1)
            ->has('leads', 5)
        );
});

it('filtert nach einer Stufe', function (): void {
    $organisation = alsMandant();
    trichterdaten();

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('leads.index', ['status' => 'won']))
        ->assertInertia(fn ($seite) => $seite
            ->has('leads', 1)
            ->where('leads.0.status', 'won')
            // Der Trichter zaehlt weiter alle -- sonst waere er kein Trichter.
            ->where('funnel.new', 1)
        );
});

it('zeigt offene Anfragen zuerst', function (): void {
    $organisation = alsMandant();
    trichterdaten();

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('leads.index'))
        ->assertInertia(fn ($seite) => $seite
            ->where('leads.0.status', 'new')
            ->where('leads.4.status', 'lost')
        );
});

it('rechnet den Median der ersten Reaktion', function (): void {
    // Der Median, nicht der Mittelwert: ein einzelner Vorgang nach drei
    // Wochen zoege einen Mittelwert so weit hoch, dass die Zahl nichts mehr
    // aussagt.
    $organisation = alsMandant();

    foreach ([60, 120, 3000] as $sekunden) {
        $kontakt = Contact::create(['first_name' => 'Median', 'last_name' => (string) $sekunden]);
        $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message);
        $lead->first_responded_at = CarbonImmutable::now();
        $lead->first_response_seconds = $sekunden;
        $lead->save();
    }

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('leads.index'))
        ->assertInertia(fn ($seite) => $seite->where('speed_to_lead', 120));
});

it('nimmt eine Anfrage von Hand auf', function (): void {
    $organisation = alsMandant();
    $kontakt = Contact::create(['first_name' => 'Anruf', 'last_name' => 'Eingegangen']);
    $behandlung = Treatment::factory()->create();

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)->post(route('leads.store'), [
        'contact' => (string) $kontakt->uuid,
        'treatment' => (string) $behandlung->uuid,
        'source' => 'phone',
    ])->assertRedirect();

    $lead = Lead::query()->firstOrFail();

    expect($lead->source)->toBe(LeadSource::Phone)
        ->and($lead->treatment_id)->toBe($behandlung->getKey());
});

it('vermerkt eine Reaktion und schliesst mit Grund', function (): void {
    $organisation = alsMandant();
    $kontakt = Contact::create(['first_name' => 'Ilse', 'last_name' => 'Wartet']);
    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)->post(route('leads.respond', ['lead' => $lead->uuid]))->assertRedirect();

    expect($lead->fresh()?->status)->toBe(LeadStatus::Contacted);

    actingAs($benutzer)->post(route('leads.lose', ['lead' => $lead->uuid]), [
        'reason' => 'too_expensive',
    ])->assertRedirect();

    $frisch = $lead->fresh();

    expect($frisch?->status)->toBe(LeadStatus::Lost)
        ->and($frisch?->lost_reason)->toBe(LeadLostReason::TooExpensive);
});

it('nimmt keinen erfundenen Grund an', function (): void {
    $organisation = alsMandant();
    $kontakt = Contact::create(['first_name' => 'Jens', 'last_name' => 'Offen']);
    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->post(route('leads.lose', ['lead' => $lead->uuid]), ['reason' => 'keine_lust'])
        ->assertSessionHasErrors('reason');
});

it('laesst ohne contacts.manage niemanden an die Anfragen', function (): void {
    $organisation = alsMandant();
    $behandler = User::factory()->fuer($organisation, Role::Practitioner)->create();

    actingAs($behandler)->get(route('leads.index'))->assertForbidden();
});

it('zeigt nie eine Anfrage einer anderen Organisation', function (): void {
    $erste = alsMandant();
    $eigener = Contact::create(['first_name' => 'Hier', 'last_name' => 'Eigen']);
    app(Leadverwaltung::class)->erfasse($eigener, null, LeadSource::Phone);

    $zweite = organisation('Zweite Praxis');
    app(TenantContext::class)->runAs($zweite, function (): void {
        $fremder = Contact::create(['first_name' => 'Dort', 'last_name' => 'Fremd']);
        app(Leadverwaltung::class)->erfasse($fremder, null, LeadSource::Phone);
    });

    $benutzer = User::factory()->fuer($erste, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('leads.index'))
        ->assertInertia(fn ($seite) => $seite
            ->has('leads', 1)
            ->where('leads.0.contact', 'Hier Eigen')
            ->where('funnel.new', 1)
        );
});
