<?php

declare(strict_types=1);

use App\Agent\Agentenlauf;
use App\Enums\AgentAction;
use App\Enums\AgentMode;
use App\Enums\AppointmentStatus;
use App\Enums\BookingState;
use App\Enums\CancellationReason;
use App\Jobs\LueckeFuellen;
use App\Models\AgentDialog;
use App\Models\AgentRun;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\SlotHold;
use App\Models\Treatment;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\travelTo;

use Tests\Feature\Agent\Agentenaufbau;
use Tests\Feature\Agent\Testmodell;
use Tests\Feature\Verfuegbarkeit\Aufbau;

/*
|--------------------------------------------------------------------------
| WP-24, offen: Verschieben und Absagen durch den Agenten
|--------------------------------------------------------------------------
|
| "Der Agent entwirft dazu eine Antwort, fuehrt sie aber nicht aus." Seit dem
| 26.09.2026 fuehrt er sie aus -- unter vier Bedingungen, jede eine Stelle,
| an der er sonst einem Menschen uebergibt:
|
| 1. **Die Person ist bekannt.** Die Kennung gehoert zu einem Kontakt.
| 2. **Es gibt genau einen anstehenden Termin.** Bei zweien fragt ein Mensch
|    nach, welcher gemeint ist -- raten waere hier eine Absage am falschen Tag.
| 3. **Es wird ausdruecklich bestaetigt**, mit Tag und Uhrzeit im Satz.
| 4. **Nur im Modus `auto`.** Im Vorschlagsmodus rechnet er nur aus, was er
|    sagen wuerde.
|
| Eine Absage gibt die Zeit sofort an die Warteliste (WP-25) -- das ist der
| Grund, warum sich das lohnt.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Queue::fake();
});

/**
 * Eine bekannte Person mit einem gebuchten Termin am 14.01. um 10:00.
 *
 * @return array{Agentenaufbau, Aufbau, Appointment}
 */
function personMitTermin(AgentMode $modus = AgentMode::Auto): array
{
    $agent = new Agentenaufbau([], $modus);

    $praxis = new Aufbau;
    $praxis->erzeugeSlots('2027-01-12', '2027-01-20');

    $behandlung = Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);
    $praxis->art->treatment_id = $behandlung->getKey();
    $praxis->art->save();

    $kontakt = Contact::create(['first_name' => 'Annika', 'last_name' => 'Müller']);

    $identitaet = $agent->gespraech->channelIdentity;
    $identitaet->contact_id = $kontakt->getKey();
    $identitaet->save();

    $agent->gespraech->contact_id = $kontakt->getKey();
    $agent->gespraech->save();

    $termin = app(Terminplaner::class)->buche(
        vorschlagUm($praxis, '2027-01-14 10:00'),
        $kontakt,
    );

    return [$agent, $praxis, $termin];
}

function vorschlagUm(Aufbau $praxis, string $ortszeit): Slotvorschlag
{
    $gesucht = CarbonImmutable::parse($ortszeit, 'Europe/Berlin')->utc();

    foreach (app(Verfuegbarkeit::class)->freieStartzeiten(
        art: $praxis->art,
        von: $gesucht->startOfDay(),
        bis: $gesucht->endOfDay(),
    ) as $vorschlag) {
        if ($vorschlag->startsAt->equalTo($gesucht)) {
            return $vorschlag;
        }
    }

    throw new RuntimeException("Kein Vorschlag um {$ortszeit}.");
}

function sagt(Agentenaufbau $agent, string $text, string $absicht = 'other'): ?AgentRun
{
    $agent->modell->anfragen = [];
    $agent->modell->antworten([Testmodell::einordnung(absicht: $absicht, sicherheit: 0.95)]);

    return app(Agentenlauf::class)->fuer($agent->nachricht($text));
}

/* Absagen ------------------------------------------------------------------ */

it('sagt einen Termin nach ausdruecklicher Rueckfrage ab', function (): void {
    [$agent, , $termin] = personMitTermin();

    $lauf = sagt($agent, 'Ich muss meinen Termin leider absagen', 'cancel_request');

    // Erst die Rueckfrage, mit Tag und Uhrzeit -- noch ist nichts geschehen.
    expect($lauf?->action)->toBe(AgentAction::Answered)
        ->and((string) $lauf?->suggestion)->toContain('14.01.')
        ->and((string) $lauf?->suggestion)->toContain('10:00')
        ->and($termin->refresh()->status)->not->toBe(AppointmentStatus::Cancelled);

    $lauf = sagt($agent, 'Ja, bitte');

    expect($termin->refresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($termin->cancellation_reason)->toBe(CancellationReason::Contact)
        ->and((string) $lauf?->suggestion)->toContain('abgesagt')
        ->and(AgentDialog::query()->firstOrFail()->state)->toBe(BookingState::Geaendert);

    // Die Luecke geht an die Warteliste.
    Queue::assertPushed(LueckeFuellen::class);
});

it('sagt ohne Ja nichts ab', function (): void {
    [$agent, , $termin] = personMitTermin();

    sagt($agent, 'Ich muss absagen', 'cancel_request');
    $lauf = sagt($agent, 'Nein, doch nicht');

    expect($termin->refresh()->status)->not->toBe(AppointmentStatus::Cancelled)
        ->and((string) $lauf?->suggestion)->toContain('bleibt');
});

it('uebergibt bei mehreren anstehenden Terminen an einen Menschen', function (): void {
    [$agent, $praxis, $termin] = personMitTermin();

    app(Terminplaner::class)->buche(vorschlagUm($praxis, '2027-01-18 11:00'), $termin->contact);

    $lauf = sagt($agent, 'Ich muss absagen', 'cancel_request');

    // Raten waere eine Absage am falschen Tag.
    expect($lauf?->action)->toBe(AgentAction::Escalated)
        ->and(Appointment::query()->where('status', AppointmentStatus::Cancelled->value)->count())->toBe(0);
});

it('uebergibt, wenn niemand weiss, wer da schreibt', function (): void {
    [$agent] = personMitTermin();

    $agent->gespraech->contact_id = null;
    $agent->gespraech->save();
    $identitaet = $agent->gespraech->channelIdentity;
    $identitaet->contact_id = null;
    $identitaet->save();

    $lauf = sagt($agent, 'Bitte meinen Termin absagen', 'cancel_request');

    expect($lauf?->action)->toBe(AgentAction::Escalated)
        ->and(Appointment::query()->where('status', AppointmentStatus::Cancelled->value)->count())->toBe(0);
});

it('sagt im Modus suggest nichts ab', function (): void {
    [$agent, , $termin] = personMitTermin(AgentMode::Suggest);

    sagt($agent, 'Ich muss absagen', 'cancel_request');
    sagt($agent, 'Ja');

    expect($termin->refresh()->status)->not->toBe(AppointmentStatus::Cancelled);
});

/* Verschieben -------------------------------------------------------------- */

it('verschiebt einen Termin nach Auswahl und Bestaetigung -- dieselbe Zeile', function (): void {
    [$agent, , $termin] = personMitTermin();
    $vorher = $termin->starts_at;

    $lauf = sagt($agent, 'Kann ich meinen Termin verschieben?', 'reschedule_request');

    expect((string) $lauf?->suggestion)->toContain('Diese Termine sind frei')
        ->and(AgentDialog::query()->firstOrFail()->state)->toBe(BookingState::VerschiebenVorschlagen);

    sagt($agent, 'Der erste passt');

    // Der neue Slot ist gehalten, der alte noch belegt.
    expect(SlotHold::query()->gueltig()->count())->toBe(1)
        ->and($termin->refresh()->starts_at->equalTo($vorher))->toBeTrue();

    $lauf = sagt($agent, 'Ja, genau so');

    $termin->refresh();

    expect(Appointment::query()->count())->toBe(1)
        ->and($termin->starts_at->equalTo($vorher))->toBeFalse()
        ->and($termin->status)->not->toBe(AppointmentStatus::Cancelled)
        ->and((string) $lauf?->suggestion)->toContain('verschoben')
        // Der Hold ist nicht liegengeblieben.
        ->and(SlotHold::query()->gueltig()->count())->toBe(0);
});

it('gibt den gehaltenen Slot frei, wenn das Verschieben abgelehnt wird', function (): void {
    [$agent, , $termin] = personMitTermin();
    $vorher = $termin->starts_at;

    sagt($agent, 'Verschieben bitte', 'reschedule_request');
    sagt($agent, 'Der erste passt');
    sagt($agent, 'Nein, lieber nicht');

    expect($termin->refresh()->starts_at->equalTo($vorher))->toBeTrue()
        ->and(SlotHold::query()->gueltig()->count())->toBe(0);
});

/* Danach ------------------------------------------------------------------- */

it('nimmt nach einer Absage eine neue Buchung an', function (): void {
    [$agent] = personMitTermin();

    sagt($agent, 'Ich muss absagen', 'cancel_request');
    sagt($agent, 'Ja');

    $agent->modell->anfragen = [];
    $agent->modell->antworten([Testmodell::einordnung(absicht: 'booking_request', sicherheit: 0.95, behandlung: 'Botox')]);
    $lauf = app(Agentenlauf::class)->fuer($agent->nachricht('Dann gern einen neuen Termin für Botox'));

    // Ein abgesagter Termin ist kein "bestehender Termin" mehr (G9).
    expect((string) $lauf?->suggestion)->toContain('Diese Termine sind frei');
});
