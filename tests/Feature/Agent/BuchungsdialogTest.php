<?php

declare(strict_types=1);

use App\Agent\Agentenlauf;
use App\Enums\AgentAction;
use App\Enums\AgentMode;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\BookingState;
use App\Enums\ConsentType;
use App\Enums\MessageDirection;
use App\Models\AgentDialog;
use App\Models\AgentRun;
use App\Models\Appointment;
use App\Models\Consent;
use App\Models\Message;
use App\Models\SlotHold;
use App\Models\Treatment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\travelTo;

use Tests\Feature\Agent\Agentenaufbau;
use Tests\Feature\Agent\Testmodell;
use Tests\Feature\Verfuegbarkeit\Aufbau;

/*
|--------------------------------------------------------------------------
| WP-24 -- der Buchungsdialog
|--------------------------------------------------------------------------
|
| Testfaelle 11 bis 16 aus docs/fachlogik/agent.md, dazu der automatische
| Versand.
|
| **Die Saetze stammen aus dem Produkt.** Das Modell liefert Absicht und
| Entitaeten; formuliert wird mit Bausteinen. Deshalb pruefen diese Tests
| Zustaende und Wirkungen, nicht Formulierungen.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Queue::fake();
});

/**
 * Eine Praxis mit Slots, einem Gespraech und einem vorhersagbaren Modell.
 *
 * **Vorgabe ist `auto`**: die Testfaelle 11 bis 16 pruefen den Automaten, der
 * tut -- im Vorschlagsmodus rechnet er nur aus, was er sagen wuerde.
 *
 * @param  list<string>  $antworten
 * @return array{Agentenaufbau, Aufbau}
 */
function dialogaufbau(array $antworten = [], AgentMode $modus = AgentMode::Auto): array
{
    $agent = new Agentenaufbau($antworten, $modus);

    $praxis = new Aufbau;
    $praxis->erzeugeSlots('2027-01-12', '2027-01-20');

    $behandlung = Treatment::factory()->create(['name' => 'Botox', 'is_active' => true]);
    $praxis->art->treatment_id = $behandlung->getKey();
    $praxis->art->save();

    return [$agent, $praxis];
}

/** Eine Nachricht der Person, durch den Agenten geschickt. */
function schreibt(Agentenaufbau $agent, string $text, string $absicht = 'other', ?string $behandlung = null): ?AgentRun
{
    $agent->modell->anfragen = [];
    $agent->modell->antworten([Testmodell::einordnung(absicht: $absicht, sicherheit: 0.95, behandlung: $behandlung)]);

    return app(Agentenlauf::class)->fuer($agent->nachricht($text));
}

/** Der zuletzt vorgeschlagene oder gesendete Text. */
function letzterText(?AgentRun $lauf): string
{
    return (string) $lauf?->suggestion;
}

/* Der vollstaendige Weg ---------------------------------------------------- */

it('fuehrt einen Dialog bis zur Buchung', function (): void {
    // Testfall 11.
    [$agent, $praxis] = dialogaufbau();

    $lauf = schreibt($agent, 'Ich hätte gern einen Termin für Botox', 'booking_request', 'Botox');

    expect(letzterText($lauf))->toContain('Diese Termine sind frei');

    $dialog = AgentDialog::query()->firstOrFail();

    expect($dialog->state)->toBe(BookingState::SlotsVorschlagen)
        ->and($dialog->angebote())->not->toBeEmpty();

    // Der erste Vorschlag.
    $lauf = schreibt($agent, 'Der erste passt');

    expect(SlotHold::query()->gueltig()->count())->toBe(1);

    // Name steht als Profilname der Kanalidentitaet -- gefragt wird nach der
    // Einwilligung.
    expect(letzterText($lauf))->toContain('Terminnachrichten');

    $lauf = schreibt($agent, 'Ja, gern');

    expect(letzterText($lauf))->toContain('Ich fasse zusammen');

    $lauf = schreibt($agent, 'Ja bitte');

    $termin = Appointment::query()->firstOrFail();

    expect(letzterText($lauf))->toContain('Der Termin steht')
        ->and($termin->booked_via)->toBe(BookingChannel::Agent)
        ->and($termin->status)->toBe(AppointmentStatus::Pending)
        ->and($termin->consent_accepted_at)->not->toBeNull()
        ->and(AgentDialog::query()->firstOrFail()->state)->toBe(BookingState::Gebucht)
        // Der Hold ist eingeloest, nicht liegengeblieben.
        ->and(SlotHold::query()->gueltig()->count())->toBe(0);

    // Die Einwilligung haengt an der Kanalidentitaet (Entscheidung D8).
    expect(Consent::query()->where('type', ConsentType::ServiceMessages->value)->count())->toBe(1);
});

it('gibt bei einer Meinungsaenderung den Hold frei und setzt zurueck', function (): void {
    // Testfall 12.
    [$agent, $praxis] = dialogaufbau();

    $zweite = Treatment::factory()->create(['name' => 'Hyaluron', 'is_active' => true]);

    schreibt($agent, 'Termin für Botox bitte', 'booking_request', 'Botox');
    schreibt($agent, 'Der erste passt');

    $hold = SlotHold::query()->gueltig()->firstOrFail();

    schreibt($agent, 'Ach nein, lieber Hyaluron', 'booking_request', 'Hyaluron');

    expect($hold->fresh()?->giltNoch())->toBeFalse();

    $dialog = AgentDialog::query()->firstOrFail();

    expect($dialog->treatment_id)->toBe($zweite->getKey())
        ->and($dialog->state)->toBe(BookingState::SlotsVorschlagen);
});

it('erzeugt aus zwei Buchungsversuchen einen Termin und eine Rueckfrage', function (): void {
    // Testfall 13, Entscheidung G9: Doppelbuchung aus dem Chat ist der
    // Vertrauenskiller.
    [$agent, $praxis] = dialogaufbau();

    schreibt($agent, 'Termin für Botox', 'booking_request', 'Botox');
    schreibt($agent, 'Der erste passt');
    schreibt($agent, 'Ja');
    schreibt($agent, 'Ja bitte');

    expect(Appointment::query()->count())->toBe(1);

    $lauf = schreibt($agent, 'Ich hätte gern noch einen Termin', 'booking_request', 'Botox');

    expect(Appointment::query()->count())->toBe(1)
        ->and($lauf?->action)->toBe(AgentAction::Escalated);
});

it('bricht ohne passenden Slot nicht ab', function (): void {
    // Testfall 14.
    [$agent, $praxis] = dialogaufbau();

    // Nichts frei: der Kalender ist voll. Ausgedrueckt als leere
    // Slot-Tabelle -- ob die Slots nie entstanden oder alle belegt sind,
    // macht fuer den Dialog keinen Unterschied.
    DB::table('appointment_slots')->delete();

    $lauf = schreibt($agent, 'Termin für Botox bitte', 'booking_request', 'Botox');

    // **Keine Sackgasse**: uebergeben, nicht abgebrochen -- und mit einem
    // Satz, der der Person sagt, dass sich jemand meldet.
    expect($lauf?->action)->toBe(AgentAction::Escalated)
        ->and($lauf?->escalation_reason)->not->toBeNull();
});

it('eskaliert nach drei erfolglosen Klaerungsversuchen', function (): void {
    // Testfall 15: ein Agent, der viermal dasselbe fragt, ist schlimmer als
    // kein Agent.
    [$agent, $praxis] = dialogaufbau();

    Treatment::factory()->create(['name' => 'Hyaluron', 'is_active' => true]);

    // Terminwunsch ohne Behandlung -- die Praxis hat zwei im Katalog.
    schreibt($agent, 'Ich möchte einen Termin', 'booking_request');
    schreibt($agent, 'Hm', 'booking_request');
    $dritter = schreibt($agent, 'Weiß nicht', 'booking_request');

    expect($dritter?->action)->toBe(AgentAction::Answered);

    $vierter = schreibt($agent, 'Egal', 'booking_request');

    expect($vierter?->action)->toBe(AgentAction::Escalated)
        ->and(AgentDialog::query()->firstOrFail()->attempts)->toBeGreaterThan(3);
});

it('schlaegt nach einem abgelaufenen Hold neu vor, ohne zu scheitern', function (): void {
    // Testfall 16.
    [$agent, $praxis] = dialogaufbau();

    schreibt($agent, 'Termin für Botox', 'booking_request', 'Botox');
    schreibt($agent, 'Der erste passt');

    expect(SlotHold::query()->gueltig()->count())->toBe(1);

    // Der Hold laeuft ab, waehrend die Person ueberlegt.
    travelTo(CarbonImmutable::now()->addMinutes((int) config('mrs.agent.slot_hold_ttl_minutes') + 1));

    $lauf = schreibt($agent, 'Ja, gern');

    expect($lauf?->action)->toBe(AgentAction::Answered)
        ->and(letzterText($lauf))->toContain('Diese Termine sind frei')
        ->and(AgentDialog::query()->firstOrFail()->state)->toBe(BookingState::SlotsVorschlagen);
});

/* Automatischer Versand ---------------------------------------------------- */

it('schickt im Modus auto selbst und kennzeichnet die erste Antwort', function (): void {
    // Entscheidung G6, Transparenzpflicht nach EU AI Act.
    [$agent, $praxis] = dialogaufbau();

    $lauf = schreibt($agent, 'Termin für Botox', 'booking_request', 'Botox');

    $erste = Message::query()->where('direction', MessageDirection::Outbound->value)->firstOrFail();

    expect($lauf?->action)->toBe(AgentAction::Answered)
        ->and($erste->body)->toContain('KI-Assistenten')
        ->and($erste->body)->toContain('Diese Termine sind frei');

    $lauf = schreibt($agent, 'Der erste passt');

    $zweite = Message::query()
        ->where('direction', MessageDirection::Outbound->value)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->firstOrFail();

    // Die zweite traegt sie nicht mehr -- einmal je Konversation.
    expect($zweite->body)->not->toContain('KI-Assistenten');
});

it('schickt im Modus suggest nichts', function (): void {
    [$agent, $praxis] = dialogaufbau(modus: AgentMode::Suggest);

    $lauf = schreibt($agent, 'Termin für Botox', 'booking_request', 'Botox');

    expect($lauf?->action)->toBe(AgentAction::Suggested)
        ->and(Message::query()->where('direction', MessageDirection::Outbound->value)->count())->toBe(0);
});

it('beendet den Dialog bei einem Komplikationssignal und gibt den Hold frei', function (): void {
    // Die harte Weiche aus WP-23 gilt auch im laufenden Dialog.
    Notification::fake();

    [$agent, $praxis] = dialogaufbau();

    schreibt($agent, 'Termin für Botox', 'booking_request', 'Botox');
    schreibt($agent, 'Der erste passt');

    expect(SlotHold::query()->gueltig()->count())->toBe(1);

    $lauf = schreibt($agent, 'Ich habe seit gestern Fieber');

    expect($lauf?->action)->toBe(AgentAction::Escalated)
        ->and($lauf?->guardrails)->toContain('complication')
        ->and(SlotHold::query()->gueltig()->count())->toBe(0);
});

it('bucht ohne Einwilligung nicht', function (): void {
    [$agent, $praxis] = dialogaufbau();

    schreibt($agent, 'Termin für Botox', 'booking_request', 'Botox');
    schreibt($agent, 'Der erste passt');

    $lauf = schreibt($agent, 'Nein, lieber nicht');

    expect(Appointment::query()->count())->toBe(0)
        ->and($lauf?->action)->toBe(AgentAction::Escalated)
        // Kein Hold, der einen echten Termin blockiert.
        ->and(SlotHold::query()->gueltig()->count())->toBe(0);
});

it('ueberlebt ein laufender Dialog den Not-Aus nicht', function (): void {
    [$agent, $praxis] = dialogaufbau();

    schreibt($agent, 'Termin für Botox', 'booking_request', 'Botox');
    schreibt($agent, 'Der erste passt');

    $organisation = $agent->organisation;
    $organisation->settings = ['agent' => ['enabled' => false]];
    $organisation->save();

    $lauf = schreibt($agent, 'Ja, gern');

    expect($lauf?->action)->toBe(AgentAction::Skipped)
        ->and($lauf?->guardrails)->toContain('tenant_disabled')
        ->and(SlotHold::query()->gueltig()->count())->toBe(0);
});

it('haelt im Modus suggest weder Slot noch bucht er', function (): void {
    // **Vorschlagen heisst vorschlagen.** Im Modus `suggest` geht kein Satz
    // hinaus -- also darf der Dialog auch nichts tun, was von einem Satz
    // abhinge, den niemand gelesen hat: kein Hold, keine Einwilligung, kein
    // Termin.
    [$agent, $praxis] = dialogaufbau(modus: AgentMode::Suggest);

    schreibt($agent, 'Termin für Botox', 'booking_request', 'Botox');
    schreibt($agent, 'Der erste passt');
    schreibt($agent, 'Ja, gern');
    schreibt($agent, 'Ja bitte');

    expect(Appointment::query()->count())->toBe(0)
        ->and(SlotHold::query()->count())->toBe(0)
        ->and(Consent::query()->count())->toBe(0);
});
