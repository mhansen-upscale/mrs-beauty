<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\Role;
use App\Kanaele\Konversationen;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Note;
use App\Models\Tag;
use App\Models\Taggable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-18, offen: "Notizen, Schlagworte und Anhaenge haben keine Oberflaeche.
| [...] am Kontakt sichtbar werden sie mit der Inbox (WP-21), wo sie
| hingehoeren." -- seit dem 26.09.2026 dort.
|--------------------------------------------------------------------------
|
| **Nur fuer die, die Kontakte pflegen** (contacts.manage). Eine Behandlerin
| liest den Posteingang mit, aber die Notizen des Empfangs ueber eine Person
| gehoeren nicht in jede Hand.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** @return array{Contact, Conversation} */
function kontaktImGespraech(): array
{
    $kontakt = Contact::create(['first_name' => 'Ina', 'last_name' => 'Schnell']);
    $identitaet = ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
        'contact_id' => $kontakt->getKey(),
    ]);

    $gespraech = app(Konversationen::class)->fuer($identitaet);
    $gespraech->contact_id = $kontakt->getKey();
    $gespraech->save();

    return [$kontakt, $gespraech];
}

it('haelt eine Notiz am Kontakt fest -- verschluesselt, mit Verfasser', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    [$kontakt] = kontaktImGespraech();

    actingAs($empfang)
        ->post(route('contacts.notes.store', ['contact' => $kontakt->uuid]), ['text' => 'Ruft lieber nachmittags an.'])
        ->assertSessionHasNoErrors();

    $notiz = Note::query()->firstOrFail();

    expect($notiz->body)->toBe('Ruft lieber nachmittags an.')
        ->and($notiz->notable_id)->toBe($kontakt->getKey())
        ->and($notiz->author_user_id)->toBe($empfang->getKey())
        ->and((string) DB::table('notes')->value('body'))->not->toContain('nachmittags');
});

it('zeigt Notizen und Schlagworte im Posteingang', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    [$kontakt, $gespraech] = kontaktImGespraech();

    actingAs($empfang)->post(route('contacts.notes.store', ['contact' => $kontakt->uuid]), ['text' => 'Ruft lieber nachmittags an.']);
    actingAs($empfang)->post(route('contacts.tags.store', ['contact' => $kontakt->uuid]), ['name' => 'Stammkundin']);

    actingAs($empfang)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('conversation.kontakt.notizen.0.text', 'Ruft lieber nachmittags an.')
            ->where('conversation.kontakt.schlagworte.0.name', 'Stammkundin')
        );
});

it('zeigt einer Behandlerin im Posteingang keine Notizen', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    $behandlerin = User::factory()->fuer($praxis, Role::Practitioner)->create();
    [$kontakt, $gespraech] = kontaktImGespraech();

    actingAs($empfang)->post(route('contacts.notes.store', ['contact' => $kontakt->uuid]), ['text' => 'Ruft lieber nachmittags an.']);

    actingAs($behandlerin)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('conversation.kontakt.name', 'Ina Schnell')
            ->missing('conversation.kontakt.notizen')
        );
});

it('vergibt ein Schlagwort einmal und nutzt vorhandene wieder', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    [$kontakt] = kontaktImGespraech();
    $andere = Contact::create(['first_name' => 'Bea', 'last_name' => 'Winter']);

    actingAs($empfang)->post(route('contacts.tags.store', ['contact' => $kontakt->uuid]), ['name' => 'Stammkundin']);
    actingAs($empfang)->post(route('contacts.tags.store', ['contact' => $kontakt->uuid]), ['name' => 'stammkundin ']);
    actingAs($empfang)->post(route('contacts.tags.store', ['contact' => $andere->uuid]), ['name' => 'Stammkundin']);

    expect(Tag::query()->count())->toBe(1)
        ->and(Taggable::query()->count())->toBe(2);
});

it('entfernt Notiz und Schlagwort -- nur am eigenen Kontakt', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    [$kontakt] = kontaktImGespraech();
    $andere = Contact::create(['first_name' => 'Bea', 'last_name' => 'Winter']);

    actingAs($empfang)->post(route('contacts.notes.store', ['contact' => $kontakt->uuid]), ['text' => 'Allergie auf Pflaster.']);
    actingAs($empfang)->post(route('contacts.tags.store', ['contact' => $kontakt->uuid]), ['name' => 'Stammkundin']);

    $notiz = Note::query()->firstOrFail();
    $schlagwort = Tag::query()->firstOrFail();

    // Ueber einen fremden Kontakt kommt niemand an die Notiz.
    actingAs($empfang)
        ->delete(route('contacts.notes.destroy', ['contact' => $andere->uuid, 'note' => $notiz->uuid]))
        ->assertNotFound();

    actingAs($empfang)->delete(route('contacts.notes.destroy', ['contact' => $kontakt->uuid, 'note' => $notiz->uuid]))->assertRedirect();
    actingAs($empfang)->delete(route('contacts.tags.destroy', ['contact' => $kontakt->uuid, 'tag' => $schlagwort->uuid]))->assertRedirect();

    expect(Note::query()->count())->toBe(0)
        ->and(Taggable::query()->count())->toBe(0);
});

it('laesst ohne contacts.manage keine Notiz anlegen', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $behandlerin = User::factory()->fuer($praxis, Role::Practitioner)->create();
    [$kontakt] = kontaktImGespraech();

    actingAs($behandlerin)
        ->post(route('contacts.notes.store', ['contact' => $kontakt->uuid]), ['text' => 'Test'])
        ->assertForbidden();
});
