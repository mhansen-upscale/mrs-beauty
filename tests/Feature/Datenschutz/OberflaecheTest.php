<?php

declare(strict_types=1);

use App\Datenschutz\Aufbewahrung;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\RetentionSubject;
use App\Enums\Role;
use App\Leads\Leadverwaltung;
use App\Models\Contact;
use App\Models\DataSubjectRequest;
use App\Models\Lead;
use App\Models\RetentionPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-18 -- Oberfläche: Fristen, Vorschau, Betroffenenrechte
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** Eine Anfrage, die nach der Frist faellig waere. */
function faelligeAnfrage(): Lead
{
    $kontakt = Contact::create(['first_name' => 'Alt', 'last_name' => 'Bestand']);
    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message);
    $lead->status = LeadStatus::New;
    $lead->save();

    Lead::query()->whereKey($lead->getKey())->update(['created_at' => CarbonImmutable::now()->subDays(400)]);

    return $lead->refresh();
}

it('zeigt die Fristen samt Vorschau', function (): void {
    $organisation = alsMandant();
    faelligeAnfrage();

    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($benutzer)
        ->get(route('privacy.index'))
        ->assertInertia(fn ($seite) => $seite
            ->component('organisation/Datenschutz')
            ->has('policies', count(RetentionSubject::cases()))
            ->where('faellig_gesamt', 1)
        );

    // Die Seite zeigt nur -- sie loescht nicht.
    expect(Lead::query()->count())->toBe(1);
});

it('setzt die Fristen erst auf ausdrueckliche Anweisung durch', function (): void {
    $organisation = alsMandant();
    faelligeAnfrage();

    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($benutzer)->get(route('privacy.index'));

    expect(Lead::query()->count())->toBe(1);

    actingAs($benutzer)->post(route('privacy.enforce'))->assertRedirect();

    expect(Lead::query()->count())->toBe(0);
});

it('aendert eine Frist', function (): void {
    $organisation = alsMandant();
    app(Aufbewahrung::class)->richteEin();

    $regel = RetentionPolicy::query()->where('subject', 'chat_attachment')->firstOrFail();
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($benutzer)->patch(route('privacy.policies.update', ['policy' => $regel->uuid]), [
        'retention_days' => 30,
        'action' => 'delete',
        'is_active' => true,
    ])->assertRedirect();

    expect($regel->fresh()?->retention_days)->toBe(30);
});

it('nimmt keine Frist von null Tagen an', function (): void {
    // Eine Frist von null Tagen waere ein Loeschlauf ueber alles, sofort.
    $organisation = alsMandant();
    app(Aufbewahrung::class)->richteEin();

    $regel = RetentionPolicy::query()->firstOrFail();
    $benutzer = User::factory()->fuer($organisation, Role::Owner)->create();

    actingAs($benutzer)->patch(route('privacy.policies.update', ['policy' => $regel->uuid]), [
        'retention_days' => 0,
        'action' => 'delete',
        'is_active' => true,
    ])->assertSessionHasErrors('retention_days');
});

it('laedt die Auskunft als Datei herunter, ohne sie aufzubewahren', function (): void {
    $organisation = alsMandant();
    $kontakt = Contact::create(['first_name' => 'Auskunft', 'last_name' => 'Verlangt', 'email' => 'a@praxis.test']);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    $antwort = actingAs($benutzer)->get(route('contacts.export', ['contact' => $kontakt->uuid]));

    $antwort->assertOk()->assertHeader('content-type', 'application/json; charset=UTF-8');

    $inhalt = $antwort->streamedContent();

    expect($inhalt)->toContain('Verlangt')
        ->and(DataSubjectRequest::query()->count())->toBe(1)
        // Der Vorgang haelt fest, dass Auskunft erteilt wurde -- nicht was
        // darin stand.
        ->and(json_encode(DataSubjectRequest::query()->firstOrFail()->result))->not->toContain('Verlangt');
});

it('loescht einen Kontakt ueber die Betroffenenrechte', function (): void {
    $organisation = alsMandant();
    $kontakt = Contact::create(['first_name' => 'Loeschung', 'last_name' => 'Verlangt']);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->delete(route('contacts.destroy', ['contact' => $kontakt->uuid]))
        ->assertRedirect();

    expect(Contact::query()->count())->toBe(0)
        // Der Nachweis ueberlebt den Kontakt.
        ->and(DataSubjectRequest::query()->count())->toBe(1);
});

it('laesst ohne organization.manage niemanden an die Fristen', function (): void {
    $organisation = alsMandant();
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($empfang)->get(route('privacy.index'))->assertForbidden();
});
