<?php

declare(strict_types=1);

use App\Abrechnung\Kontingente;
use App\Agent\Agentenlauf;
use App\Audit\Impersonation;
use App\Enums\AgentAction;
use App\Enums\AppointmentStatus;
use App\Enums\AuditEvent;
use App\Enums\GuardrailHit;
use App\Enums\Role;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionChangeAction;
use App\Enums\SubscriptionChangeStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organization;
use App\Models\SlotHold;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Notifications\Terminnachricht;
use App\Tenancy\TenantContext;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

use Tests\Feature\Agent\Agentenaufbau;
use Tests\Feature\Agent\Testmodell;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-34c, Abnahmekriterien 1 bis 10 -- die Zugangslage an einer Stelle
|--------------------------------------------------------------------------
|
| Unbezahlt, pausiert, Testphase abgelaufen, gekuendigt: vier Wege zu einer
| Abo-Sperre, **eine Stelle**, die sie kennt (`Subscription::zugang()`).
| Eine Abo-Sperre ist keine Betreiber-Sperre: die Praxis kommt bis zur
| Abo-Seite, Erinnerungen an gebuchte Termine gehen weiter (WP-06, B17).
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Ein Abo der laufenden Praxis in einem bestimmten Zustand.
 *
 * @param  array<string, mixed>  $felder
 */
function abozustand(array $felder): Subscription
{
    $abo = new Subscription;
    $abo->status = SubscriptionStatus::Trialing;

    foreach ($felder as $feld => $wert) {
        $abo->setAttribute($feld, $wert);
    }

    $abo->save();

    return $abo;
}

/* Zugangslage -------------------------------------------------------------- */

it('sperrt, was nicht bezahlt wird -- und nur das', function (array $felder, SubscriptionAccess $lage, bool $gesperrt): void {
    alsMandant(organisation());

    $abo = abozustand($felder);

    expect($abo->zugang())->toBe($lage)
        ->and($lage->sperrtZugang())->toBe($gesperrt)
        ->and($lage->darfNutzen())->toBe(! $gesperrt);
})->with([
    'aktiv' => [['status' => SubscriptionStatus::Active, 'stripe_subscription_id' => 'sub_1'], SubscriptionAccess::Open, false],
    'Zahlung offen' => [['status' => SubscriptionStatus::PastDue, 'stripe_subscription_id' => 'sub_1'], SubscriptionAccess::Open, false],
    'gekuendigt zum Periodenende' => [['status' => SubscriptionStatus::Active, 'stripe_subscription_id' => 'sub_1', 'cancel_at_period_end' => true], SubscriptionAccess::Open, false],
    'Testphase laeuft' => [['trial_ends_at' => '2027-02-01 00:00:00'], SubscriptionAccess::Trial, false],
    'Testphase abgelaufen' => [['trial_ends_at' => '2027-01-01 00:00:00'], SubscriptionAccess::TrialExpired, true],
    'pausiert' => [['status' => SubscriptionStatus::Active, 'stripe_subscription_id' => 'sub_1', 'paused_at' => '2027-01-10 00:00:00'], SubscriptionAccess::Paused, true],
    'unbezahlt' => [['status' => SubscriptionStatus::Unpaid, 'stripe_subscription_id' => 'sub_1'], SubscriptionAccess::Unpaid, true],
    'gekuendigt' => [['status' => SubscriptionStatus::Canceled], SubscriptionAccess::Canceled, true],
]);

it('fuehrt die Inhaberin zur Abo-Seite und den Empfang zur Sperrseite', function (): void {
    $praxis = alsMandant(organisation());
    abozustand(['status' => SubscriptionStatus::Active, 'stripe_subscription_id' => 'sub_1', 'paused_at' => '2027-01-10 00:00:00']);
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
    ohneMandant();

    actingAs($inhaberin)->get(route('dashboard'))->assertRedirect(route('abo.edit'));

    // **Kein 403**: die Empfangskraft hat nichts falsch gemacht, und sie kann
    // es auch nicht loesen -- die Seite sagt, wer es kann.
    actingAs($empfang)->get(route('dashboard'))->assertRedirect(route('abo.gesperrt'));
    actingAs($empfang)->get(route('abo.gesperrt'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('settings/AboGesperrt')
            ->where('zugang', SubscriptionAccess::Paused->value)
        );
});

it('laesst bei gesperrtem Abo Abmelden, Backoffice und das Ende einer Impersonation offen', function (): void {
    $praxis = alsMandant(organisation());
    abozustand(['status' => SubscriptionStatus::Unpaid, 'stripe_subscription_id' => 'sub_1']);
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
    $betreiber = User::factory()->superAdmin()->create();
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Rueckfrage zum Abo, Ticket 4711');
    ohneMandant();

    // Der Betreiber in der Praxis landet auf der Sperrseite -- ihm fehlt
    // `billing.manage`, und auf `abo.edit` bekaeme er einen 403 ...
    actingAs($betreiber)->withSession(impersonationSitzung($sitzung))
        ->get(route('dashboard'))
        ->assertRedirect(route('abo.gesperrt'));

    // ... und er kommt wieder hinaus.
    actingAs($betreiber)->withSession(impersonationSitzung($sitzung))
        ->delete(route('impersonation.destroy'))
        ->assertRedirect(route('dashboard'));

    actingAs($betreiber)->get(route('backoffice.index'))->assertOk();
    actingAs($inhaberin)->post(route('logout'))->assertRedirect('/');
});

it('schickt Erinnerungen an gebuchte Termine trotz Abo-Sperre', function (): void {
    Notification::fake();

    alsMandant(organisation());
    $szenario = new Szenario;
    $termin = app(Terminplaner::class)->buche($szenario->vorschlag(), $szenario->kontakt, status: AppointmentStatus::Confirmed, jetzt: $szenario->jetzt());

    abozustand(['status' => SubscriptionStatus::Unpaid, 'stripe_subscription_id' => 'sub_1']);

    // Eine Patientin mit Termin soll ihn nicht verpassen, weil die Praxis eine
    // Rechnung nicht bezahlt hat (WP-06).
    travelTo($termin->starts_at->subHours(23));

    expect(Artisan::call('mrs:erinnerungen-versenden'))->toBe(0);

    Notification::assertSentOnDemand(Terminnachricht::class);
});

it('laesst den Agenten bei gesperrtem Abo schweigen', function (): void {
    $aufbau = new Agentenaufbau([
        Testmodell::einordnung(absicht: 'general_question', sicherheit: 0.95),
        'Gern.',
    ]);

    abozustand(['status' => SubscriptionStatus::Unpaid, 'stripe_subscription_id' => 'sub_1']);

    $lauf = app(Agentenlauf::class)->fuer($aufbau->nachricht('Wann haben Sie geöffnet?'));

    // Niemand saehe, was er antwortet oder bucht -- und jeder Lauf kostet.
    expect($lauf?->action)->toBe(AgentAction::Skipped)
        ->and($lauf?->guardrails)->toContain(GuardrailHit::SubscriptionLocked->value)
        ->and($aufbau->modell->anfragen)->toHaveCount(0);
});

it('zeigt auf der Buchungsseite einer gesperrten Praxis die Kontaktdaten und bucht nichts', function (): void {
    $praxis = alsMandant(organisation('Praxis am Hafen'));
    Location::factory()->create(['name' => 'Am Hafen', 'phone' => '+49 40 1234567', 'email' => 'empfang@praxis-hafen.test']);
    abozustand(['trial_ends_at' => '2027-01-01 00:00:00']);
    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $praxis->slug]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('buchung/NichtVerfuegbar')
            ->where('practice.name', 'Praxis am Hafen')
            ->where('kontakte.0.phone', '+49 40 1234567')
            ->where('kontakte.0.email', 'empfang@praxis-hafen.test')
        );

    post(route('buchung.reservieren', ['praxis' => $praxis->slug]), [])
        ->assertInertia(fn ($seite) => $seite->component('buchung/NichtVerfuegbar'));

    app(TenantContext::class)->runAs($praxis, fn () => expect(SlotHold::query()->count())->toBe(0));
});

it('sperrt kostenpflichtigen Versand bei gesperrtem Abo', function (): void {
    alsMandant(organisation());
    abozustand(['status' => SubscriptionStatus::Active, 'stripe_subscription_id' => 'sub_1', 'paused_at' => '2027-01-10 00:00:00']);

    expect(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeFalse();

    Subscription::query()->firstOrFail()->forceFill(['paused_at' => null])->save();

    expect(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeTrue();
});

/* Testphase ---------------------------------------------------------------- */

it('rechnet die Testphase ab dem Anlegen der Praxis, nicht ab der Abo-Zeile', function (): void {
    // Die Zeile entsteht beim ersten Zugriff -- beim Agenten, beim Monatslauf,
    // auf der Abo-Seite. Mit `now()` bekaeme jede Praxis eine Testphase, die
    // irgendwann beginnt.
    $praxis = organisation();
    $praxis->forceFill(['created_at' => CarbonImmutable::now()->subDays(40)])->save();
    alsMandant($praxis);

    $abo = app(Kontingente::class)->abo();

    expect($abo->trial_ends_at?->toDateString())->toBe(CarbonImmutable::now()->subDays(40)->addDays((int) config('mrs.billing.trial_days'))->toDateString())
        ->and($abo->zugang())->toBe(SubscriptionAccess::TrialExpired);
});

it('rechnet eine Praxis ohne Abo-Zeile ab ihrem Anlegen', function (): void {
    $praxis = organisation();
    $praxis->forceFill(['created_at' => CarbonImmutable::now()->subDays(40)])->save();
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    // Keine Zeile, also kein Zugriff, der sie anlegen koennte -- gesperrt ist
    // die Praxis trotzdem.
    actingAs($inhaberin)->get(route('dashboard'))->assertRedirect(route('abo.edit'));

    app(TenantContext::class)->runAs($praxis, fn () => expect(Subscription::query()->count())->toBe(0));
});

it('laesst Customer Success die Testphase verlaengern -- sofort und protokolliert', function (): void {
    $praxis = alsMandant(organisation());
    abozustand(['trial_ends_at' => '2027-01-01 00:00:00']);
    ohneMandant();

    actingAs(User::factory()->customerSuccess()->create())
        ->post(route('backoffice.testphase', ['organisation' => $praxis->uuid]), [
            'tage' => 14,
            'grund' => 'Rückruf am Montag vereinbart',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    alsMandant($praxis);

    $abo = Subscription::query()->firstOrFail();
    $eingriff = SubscriptionChange::query()->firstOrFail();

    // Von heute an gerechnet, nicht vom abgelaufenen Ende -- sonst schenkte
    // eine Verlaengerung um 14 Tage nach drei Wochen Ablauf nichts.
    expect($abo->zugang())->toBe(SubscriptionAccess::Trial)
        ->and($abo->trial_ends_at?->toDateString())->toBe('2027-01-26')
        ->and($eingriff->action)->toBe(SubscriptionChangeAction::ExtendTrial)
        ->and($eingriff->status)->toBe(SubscriptionChangeStatus::Done)
        ->and(AuditLog::query()->where('event', AuditEvent::SubscriptionTrialExtended->value)->first()?->reason)->toContain('Rückruf');
});

it('verlaengert nicht laenger als erlaubt', function (): void {
    $praxis = alsMandant(organisation());
    abozustand(['trial_ends_at' => '2027-01-01 00:00:00']);
    ohneMandant();

    actingAs(User::factory()->customerSuccess()->create())
        ->post(route('backoffice.testphase', ['organisation' => $praxis->uuid]), [
            'tage' => (int) config('mrs.billing.trial_verlaengerung_max_tage') + 1,
            'grund' => 'Großzügig',
            'current_password' => 'password',
        ])
        ->assertSessionHasErrors('tage');
});

it('verlaengert keine Testphase, wenn schon ein Stripe-Abo laeuft', function (): void {
    $praxis = alsMandant(organisation());
    abozustand(['status' => SubscriptionStatus::Active, 'stripe_subscription_id' => 'sub_1']);
    ohneMandant();

    actingAs(User::factory()->superAdmin()->create())
        ->post(route('backoffice.testphase', ['organisation' => $praxis->uuid]), [
            'tage' => 14,
            'grund' => 'Aus Versehen',
            'current_password' => 'password',
        ])
        ->assertSessionHasErrors('tage');

    expect(Organization::query()->whereKey($praxis->getKey())->exists())->toBeTrue();
});
