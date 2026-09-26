<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Kanaele\Konversationen;
use App\Leads\Leadverwaltung;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-17 -- der Statuswechsel aus Nachrichten
|--------------------------------------------------------------------------
|
| "Heute vermerkt das Team die Reaktion; der Agent kann das spaeter selbst."
| Seit dem 26.09.2026 vermerkt sie die Antwort selbst: wer im Posteingang
| antwortet -- Mensch oder Assistent --, hat reagiert. Speed-to-Lead misst
| damit, was wirklich geschah, und nicht, wann jemand daran dachte, einen
| Knopf zu druecken.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Queue::fake();
});

/** @return array{Conversation, Lead, User} */
function anfrageImPosteingang(): array
{
    $praxis = alsMandant(organisation('Demo-Praxis'));

    $kontakt = Contact::create(['first_name' => 'Ina', 'last_name' => 'Schnell']);
    $identitaet = ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
        'contact_id' => $kontakt->getKey(),
    ]);

    $gespraech = app(Konversationen::class)->fuer($identitaet);
    $lead = app(Leadverwaltung::class)->erfasse($kontakt, null, LeadSource::Message);

    return [$gespraech, $lead, User::factory()->fuer($praxis, Role::Reception)->create()];
}

it('vermerkt die erste Antwort im Posteingang als Reaktion', function (): void {
    [$gespraech, $lead, $empfang] = anfrageImPosteingang();

    travelTo(CarbonImmutable::parse('2027-01-12 08:07:00', 'UTC'));

    actingAs($empfang)
        ->post(route('inbox.reply', ['conversation' => $gespraech->uuid]), ['body' => 'Gern, wann passt es Ihnen?'])
        ->assertRedirect();

    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::Contacted)
        ->and($lead->first_response_seconds)->toBe(420);
});

it('aendert die erste Reaktion durch eine zweite Antwort nicht', function (): void {
    [$gespraech, $lead, $empfang] = anfrageImPosteingang();

    travelTo(CarbonImmutable::parse('2027-01-12 08:07:00', 'UTC'));
    actingAs($empfang)->post(route('inbox.reply', ['conversation' => $gespraech->uuid]), ['body' => 'Gern.']);

    travelTo(CarbonImmutable::parse('2027-01-12 09:00:00', 'UTC'));
    actingAs($empfang)->post(route('inbox.reply', ['conversation' => $gespraech->uuid]), ['body' => 'Noch Fragen?']);

    expect($lead->refresh()->first_response_seconds)->toBe(420);
});

it('laesst einen Lead mit Termin auf Termin stehen', function (): void {
    [$gespraech, $lead, $empfang] = anfrageImPosteingang();

    $lead->status = LeadStatus::Scheduled;
    $lead->save();

    actingAs($empfang)->post(route('inbox.reply', ['conversation' => $gespraech->uuid]), ['body' => 'Bis morgen!']);

    expect($lead->refresh()->status)->toBe(LeadStatus::Scheduled);
});

it('vermerkt nichts ohne zugeordneten Kontakt', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();

    $identitaet = ChannelIdentity::create(['channel' => ChannelType::WhatsApp, 'external_id' => '4915100000000']);
    $gespraech = app(Konversationen::class)->fuer($identitaet);

    actingAs($empfang)
        ->post(route('inbox.reply', ['conversation' => $gespraech->uuid]), ['body' => 'Hallo'])
        ->assertRedirect();

    expect(Lead::query()->count())->toBe(0);
});
