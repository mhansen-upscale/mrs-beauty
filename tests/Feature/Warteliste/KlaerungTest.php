<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancellationReason;
use App\Enums\MessageDirection;
use App\Enums\Role;
use App\Enums\WaitlistOfferStatus;
use App\Enums\WaitlistStatus;
use App\Enums\WaitlistTrigger;
use App\Kanaele\Konversationen;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Models\WaitlistOffer;
use App\Termine\Terminplaner;
use App\Warteliste\Angebotsantwort;
use App\Warteliste\Vergabelauf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Warteliste\Wartelistenaufbau;

/*
|--------------------------------------------------------------------------
| WP-25 -- der wackelige Termin
|--------------------------------------------------------------------------
|
| Ausloeser 3 (docs/fachlogik/warteliste.md): keine Reaktion auf die
| Erinnerung. Der Slot wird parallel angeboten, ohne den bestehenden Termin
| anzutasten. "Nimmt jemand an, wird der wackelige Termin erst nach
| Rueckfrage beim Team aufgeloest. Das ist ausdruecklich kein automatischer
| Vorgang."
|
| Bis zum 26.09.2026 fehlte die Aufgabe im Produkt, die an diese Rueckfrage
| erinnert: die Wartende bekam "wir klaeren das", und dann klaerte es
| niemand.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Queue::fake();
});

/**
 * Ein gebuchter Termin ohne Reaktion auf die Erinnerung, ein paralleles
 * Angebot -- und die Zusage der Wartenden.
 *
 * @return array{Wartelistenaufbau, Appointment, WaitlistOffer, User}
 */
function wackeligerTermin(): array
{
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender('Ahrens');

    $wackelig = app(Terminplaner::class)->buche(
        $aufbau->slot(),
        Contact::create(['first_name' => 'Bernd', 'last_name' => 'Schuster']),
    );

    $angebot = app(Vergabelauf::class)->biete($aufbau->slot(), WaitlistTrigger::NoResponse)
        ?? throw new RuntimeException('Kein Angebot entstanden.');

    app(Angebotsantwort::class)->nimmAn($angebot, zusageAufAngebot($angebot));

    $empfang = User::factory()->fuer($aufbau->organisation, Role::Owner)->create();

    return [$aufbau, $wackelig, $angebot->refresh(), $empfang];
}

/** Das "Ja" der Wartenden. */
function zusageAufAngebot(WaitlistOffer $angebot): Message
{
    $identitaet = $angebot->entry->contact->channelIdentities()->firstOrFail();
    $gespraech = app(Konversationen::class)->fuer($identitaet);

    return app(Konversationen::class)->nimmAuf($gespraech, 'ext-'.bin2hex(random_bytes(4)), 'Ja, gern')
        ?? throw new RuntimeException('Keine Nachricht.');
}

/** Die letzte Nachricht an die Wartende. */
function letzteAntwortAnWartende(): string
{
    return (string) Message::query()
        ->where('direction', MessageDirection::Outbound->value)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->firstOrFail()
        ->body;
}

it('zeigt eine parallel angenommene Zusage als offene Klaerung', function (): void {
    [, , $angebot, $empfang] = wackeligerTermin();

    actingAs($empfang)
        ->get(route('waitlist.index'))
        ->assertInertia(fn ($seite) => $seite
            ->has('klaerungen', 1)
            ->where('klaerungen.0.uuid', $angebot->uuid)
            ->where('klaerungen.0.name', 'Anna Ahrens')
            ->where('klaerungen.0.bisher', 'Bernd Schuster')
        );

    // Und dort, wo jemand sie sieht, ohne sie zu suchen.
    actingAs($empfang)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('aufgaben.klaerungen', 1));
});

it('gibt den Slot nach Rueckfrage an die Wartende', function (): void {
    [$aufbau, $wackelig, $angebot, $empfang] = wackeligerTermin();

    actingAs($empfang)
        ->post(route('waitlist.klaerung.uebergeben', ['offer' => $angebot->uuid]))
        ->assertRedirect();

    $neu = Appointment::query()->where('status', '!=', AppointmentStatus::Cancelled->value)->with('contact')->firstOrFail();

    expect($wackelig->refresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($wackelig->cancellation_reason)->toBe(CancellationReason::Practice)
        ->and($neu->contact->name())->toBe('Anna Ahrens')
        ->and($neu->starts_at->equalTo($aufbau->slot()->startsAt))->toBeTrue()
        ->and($neu->booked_via)->toBe(BookingChannel::Waitlist)
        ->and($angebot->refresh()->appointment_id)->toBe($neu->getKey())
        ->and($angebot->entry->status)->toBe(WaitlistStatus::Booked)
        ->and(letzteAntwortAnWartende())->toContain('gehört Ihnen');
});

it('laesst den bestehenden Termin stehen und sagt der Wartenden ab', function (): void {
    [, $wackelig, $angebot, $empfang] = wackeligerTermin();

    actingAs($empfang)
        ->post(route('waitlist.klaerung.behalten', ['offer' => $angebot->uuid]))
        ->assertRedirect();

    expect($wackelig->refresh()->status)->not->toBe(AppointmentStatus::Cancelled)
        ->and($angebot->refresh()->status)->toBe(WaitlistOfferStatus::Superseded)
        // Sie wartet weiter -- die Absage gilt diesem Slot, nicht ihr.
        ->and($angebot->entry->status)->toBe(WaitlistStatus::Active)
        ->and(Appointment::query()->count())->toBe(1)
        ->and(letzteAntwortAnWartende())->toContain('bleibt vergeben');
});

it('klaert jede Zusage nur einmal', function (): void {
    [, , $angebot, $empfang] = wackeligerTermin();

    actingAs($empfang)->post(route('waitlist.klaerung.behalten', ['offer' => $angebot->uuid]));

    actingAs($empfang)
        ->post(route('waitlist.klaerung.uebergeben', ['offer' => $angebot->uuid]))
        ->assertSessionHasErrors('klaerung');

    expect(Appointment::query()->count())->toBe(1);
});
