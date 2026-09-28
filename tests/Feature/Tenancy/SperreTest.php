<?php

declare(strict_types=1);

use App\Agent\Agentenlauf;
use App\Audit\Impersonation;
use App\Datenschutz\Aufbewahrung;
use App\Enums\AgentAction;
use App\Enums\GuardrailHit;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

use Tests\Feature\Agent\Agentenaufbau;
use Tests\Feature\Agent\Testmodell;

/*
|--------------------------------------------------------------------------
| WP-34a, Abnahmekriterien 1 bis 5 -- die Sperre wirkt
|--------------------------------------------------------------------------
|
| WP-34 AK 8 ("Eine gesperrte Praxis kommt nicht mehr hinein") stand als
| erfuellt im Briefing -- geprueft wurde aber nur die Spalte, nicht die
| Wirkung. `suspended_at` las allein die oeffentliche Buchungsseite.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function sperre(Organization $praxis): void
{
    $praxis->suspended_at = CarbonImmutable::now();
    $praxis->save();
}

it('meldet eine gesperrte Praxis aus der laufenden Sitzung ab', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();

    actingAs($empfang)->get(route('dashboard'))->assertOk();

    sperre($praxis);
    ohneMandant();

    // **Bei der naechsten Anfrage**, nicht beim naechsten Anmelden: eine
    // Sitzung laeuft sonst weiter, bis sie ablaeuft.
    //
    // Mit frisch geladenem Benutzer, wie jede echte Anfrage ihn aus der
    // Sitzung holt -- actingAs() reicht sonst dasselbe Objekt weiter, und an
    // dem haengt noch die Organisation aus der ersten Anfrage.
    actingAs($empfang->fresh() ?? $empfang)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Der Zugang dieser Praxis ist gesperrt. Bitte wenden Sie sich an den Support.']);

    assertGuest();
});

it('laesst eine gesperrte Praxis nicht wieder hinein', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create(['email' => 'inhaberin@praxis.test']);

    sperre($praxis);
    ohneMandant();

    post(route('login'), ['email' => 'inhaberin@praxis.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    assertGuest();
});

it('laesst einen Betreiber in der Impersonation einer gesperrten Praxis', function (): void {
    // Wer die Sperre am geltenden Mandanten prueft, wirft den Betreiber aus
    // der Praxis, der er gerade helfen soll.
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $betreiber = User::factory()->superAdmin()->create();
    $sitzung = app(Impersonation::class)->start($betreiber, $praxis, 'Sperre klaeren, Ticket 4711');

    sperre($praxis);
    ohneMandant();

    actingAs($betreiber)
        ->withSession(impersonationSitzung($sitzung))
        ->get(route('team.index'))
        ->assertOk();
});

it('laesst den Agenten einer gesperrten Praxis nicht antworten', function (): void {
    $aufbau = new Agentenaufbau([
        Testmodell::einordnung(absicht: 'general_question', sicherheit: 0.95),
        'Gern.',
    ]);

    sperre($aufbau->organisation);

    $nachricht = $aufbau->nachricht('Wann haben Sie geöffnet?');
    $lauf = app(Agentenlauf::class)->fuer($nachricht);

    // **Gespeichert, nicht bearbeitet**: die Nachricht steht im Posteingang,
    // wenn die Sperre faellt -- aber niemand hat in der Zwischenzeit in
    // ihrem Namen geantwortet.
    expect($lauf?->action)->toBe(AgentAction::Skipped)
        ->and($lauf?->guardrails)->toContain(GuardrailHit::TenantSuspended->value)
        ->and($aufbau->modell->anfragen)->toHaveCount(0)
        ->and(Message::query()->whereKey($nachricht->getKey())->exists())->toBeTrue();
});

it('gibt den Zugang nach dem Entsperren sofort frei', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($praxis, Role::Reception)->create();

    sperre($praxis);
    $praxis->suspended_at = null;
    $praxis->save();
    ohneMandant();

    actingAs($empfang)->get(route('dashboard'))->assertOk();
});

it('setzt die Aufbewahrung auch bei einer gesperrten Praxis durch', function (): void {
    // Regel 3: Fristen gelten je Datenart, nicht je Vertragsstand. Eine
    // gesperrte Praxis behielte ihre Daten sonst ueber jede Frist hinaus.
    $jetzt = CarbonImmutable::now();
    $praxis = alsMandant(organisation('Gesperrt'));
    app(Aufbewahrung::class)->richteEin();

    travelTo($jetzt->subDays(1100));
    Contact::create(['first_name' => 'Protokoll', 'last_name' => 'Alt']);
    travelTo($jetzt);

    sperre($praxis);
    ohneMandant();

    expect(Artisan::call('mrs:aufbewahrung', ['--scharf' => true]))->toBe(0);

    alsMandant($praxis);

    expect(AuditLog::query()->where('occurred_at', '<=', $jetzt->subDays(1095))->count())->toBe(0);
});
