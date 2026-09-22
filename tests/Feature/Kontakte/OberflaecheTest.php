<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\Role;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\ContactMerge;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| WP-16, Abnahmekriterien 20 bis 23 -- Suche, Zugang, Mandantengrenze
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    alsMandant();
});

it('findet ueber den exakten Nachnamen', function (): void {
    $organisation = alsMandant();
    Contact::create(['first_name' => 'Anna', 'last_name' => 'Müller', 'email' => 'anna@praxis.test']);
    Contact::create(['first_name' => 'Bea', 'last_name' => 'Schmidt']);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('contacts.index', ['search' => 'Müller']))
        ->assertInertia(fn ($seite) => $seite
            ->component('kontakte/Index')
            ->has('contacts', 1)
            ->where('contacts.0.name', 'Anna Müller')
            ->where('search_field', 'last_name')
        );
});

it('findet ueber einen Teilstring nichts', function (): void {
    // Entscheidung P8: verschluesselte Felder kennen kein LIKE. Das gehoert
    // sichtbar in die Oberflaeche, sonst haelt der Empfang die Suche fuer
    // kaputt -- geprueft wird hier, dass sie nichts Falsches liefert.
    $organisation = alsMandant();
    Contact::create(['first_name' => 'Anna', 'last_name' => 'Müller']);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('contacts.index', ['search' => 'Mül']))
        ->assertInertia(fn ($seite) => $seite->has('contacts', 0));
});

it('findet ueber die exakte E-Mail-Adresse', function (): void {
    $organisation = alsMandant();
    Contact::create(['first_name' => 'Cem', 'last_name' => 'Yildiz', 'email' => 'cem@praxis.test']);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('contacts.index', ['search' => 'cem@praxis.test']))
        ->assertInertia(fn ($seite) => $seite->has('contacts', 1)->where('search_field', 'email'));
});

it('zeigt die Kanaele eines Kontakts', function (): void {
    $organisation = alsMandant();
    $kontakt = Contact::create(['first_name' => 'Dana', 'last_name' => 'Groth']);
    $kontakt->channelIdentities()->create(['channel' => ChannelType::Instagram, 'external_id' => 'ig-4711']);

    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('contacts.index'))
        ->assertInertia(fn ($seite) => $seite
            ->has('contacts.0.identities', 1)
            ->where('contacts.0.identities.0.channel_label', 'Instagram')
        );
});

it('legt einen Kanal ueber die Oberflaeche an und entfernt ihn wieder', function (): void {
    $organisation = alsMandant();
    $kontakt = Contact::create(['first_name' => 'Emil', 'last_name' => 'Zart']);
    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)->post(route('identities.store', ['contact' => $kontakt->uuid]), [
        'channel' => 'whatsapp',
        'external_id' => '0170 1234567',
    ])->assertRedirect();

    $identitaet = ChannelIdentity::query()->firstOrFail();

    // Die Rufnummer liegt kanonisch im Index -- gefunden wird sie in jeder
    // Schreibweise.
    expect(ChannelIdentity::query()->mitKennung(ChannelType::WhatsApp, '+49 170 1234567')->count())->toBe(1);

    actingAs($benutzer)
        ->delete(route('identities.destroy', ['contact' => $kontakt->uuid, 'identity' => $identitaet->uuid]))
        ->assertRedirect();

    expect(ChannelIdentity::query()->count())->toBe(0);
});

it('fuehrt ueber die Oberflaeche zusammen und nimmt es zurueck', function (): void {
    $organisation = alsMandant();
    $gewinner = Contact::create(['first_name' => 'Frank', 'last_name' => 'Ohlsen']);
    $verlierer = Contact::create(['first_name' => 'Frank', 'last_name' => 'Ohlsen']);
    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->post(route('contacts.merge', ['contact' => $gewinner->uuid]), ['loser' => (string) $verlierer->uuid])
        ->assertRedirect();

    expect(Contact::query()->count())->toBe(1);

    $vorgang = ContactMerge::query()->firstOrFail();

    // Wer zusammengefuehrt hat, steht am Vorgang.
    expect($vorgang->merged_by_user_id)->toBe($benutzer->getKey());

    actingAs($benutzer)
        ->post(route('merges.revert', ['merge' => $vorgang->uuid]))
        ->assertRedirect();

    expect(Contact::query()->count())->toBe(2);
});

it('laesst ohne contacts.manage niemanden an die Kontakte', function (): void {
    $organisation = alsMandant();
    Contact::create(['first_name' => 'Gerd', 'last_name' => 'Halm']);

    // Die Rolle "Behandler" sieht den eigenen Kalender, aber keine Kontakte.
    $behandler = User::factory()->fuer($organisation, Role::Practitioner)->create();

    actingAs($behandler)->get(route('contacts.index'))->assertForbidden();
});

it('zeigt nie einen Kontakt einer anderen Organisation', function (): void {
    $erste = alsMandant();
    Contact::create(['first_name' => 'Hier', 'last_name' => 'Eigen']);

    $zweite = alsMandant(organisation('Zweite Praxis'));
    Contact::create(['first_name' => 'Dort', 'last_name' => 'Fremd']);

    $benutzer = User::factory()->fuer($erste, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('contacts.index'))
        ->assertInertia(fn ($seite) => $seite
            ->has('contacts', 1)
            ->where('contacts.0.name', 'Hier Eigen')
        );

    expect($zweite->name)->toBe('Zweite Praxis');
});
