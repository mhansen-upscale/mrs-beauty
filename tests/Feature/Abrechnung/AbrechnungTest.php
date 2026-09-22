<?php

declare(strict_types=1);

use App\Abrechnung\Kontingente;
use App\Abrechnung\Nutzungsuebersicht;
use App\Abrechnung\Stripe\Stripesignatur;
use App\Enums\AgentAction;
use App\Enums\ChannelType;
use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Kanaele\Konversationen;
use App\Models\AgentRun;
use App\Models\ChannelIdentity;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-06 -- Abo und Abrechnung
|--------------------------------------------------------------------------
|
| **Die Nutzung wird abgeleitet, nicht zweitgefuehrt** (B7): was Geld kostet,
| steht schon in den Fachtabellen. Eine zweite Zeile je Vorgang waere ein
| zweiter Ort fuer dieselbe Zahl.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** Eine ausgehende Nachricht mit oder ohne Kosten. */
function abrechnungsnachricht(?MessageCostCategory $kategorie, ?CarbonImmutable $wann = null): Message
{
    // Ohne Kanalverbindung: gezaehlt wird die Nachrichtenzeile, nicht der
    // Weg, auf dem sie entstanden ist.
    $identitaet = ChannelIdentity::query()->firstOr(fn () => ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
    ]));

    $gespraech = app(Konversationen::class)->fuer($identitaet);

    $nachricht = new Message;
    $nachricht->conversation_id = $gespraech->getKey();
    $nachricht->channel = ChannelType::WhatsApp;
    $nachricht->direction = MessageDirection::Outbound;
    $nachricht->status = MessageStatus::Sent;
    $nachricht->body = 'Hallo';
    $nachricht->cost_category = $kategorie;
    $nachricht->save();

    if ($wann instanceof CarbonImmutable) {
        $nachricht->forceFill(['created_at' => $wann])->save();
    }

    return $nachricht;
}

function agentenlauf(int $kosten = 12): AgentRun
{
    $identitaet = ChannelIdentity::query()->firstOr(fn () => ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
    ]));

    $gespraech = app(Konversationen::class)->fuer($identitaet);

    $lauf = new AgentRun;
    $lauf->conversation_id = $gespraech->getKey();
    $lauf->action = AgentAction::Suggested;
    $lauf->cost_tenth_cents = $kosten;
    $lauf->save();

    return $lauf;
}

/* Nutzung ------------------------------------------------------------------ */

it('zaehlt nur, was Geld kostet', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    abrechnungsnachricht(MessageCostCategory::Utility);
    abrechnungsnachricht(MessageCostCategory::Marketing);
    abrechnungsnachricht(MessageCostCategory::None);
    // Noch unbekannt -- eine Rechnung auf Verdacht ist schlimmer als eine,
    // die nachlaeuft.
    abrechnungsnachricht(null);

    $nutzung = app(Nutzungsuebersicht::class)->fuerMonat();

    expect($nutzung['nachrichten'])->toBe(4)
        ->and($nutzung['kostenpflichtigeNachrichten'])->toBe(2);
});

it('zaehlt je Kalendermonat', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    abrechnungsnachricht(MessageCostCategory::Utility);
    abrechnungsnachricht(MessageCostCategory::Utility, wann: CarbonImmutable::parse('2026-12-20 10:00:00'));

    expect(app(Nutzungsuebersicht::class)->fuerMonat()['kostenpflichtigeNachrichten'])->toBe(1);
});

it('zaehlt Assistenzlaeufe aus den Laeufen selbst', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    agentenlauf();
    agentenlauf();
    agentenlauf(kosten: 0);

    expect(app(Nutzungsuebersicht::class)->fuerMonat()['agentenlaeufe'])->toBe(2);
});

/* Kontingent --------------------------------------------------------------- */

it('beginnt in der Testphase, nicht gesperrt', function (): void {
    // Wer eine Praxis am ersten Tag aussperrt, bekommt keinen zweiten.
    alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();

    expect($abo->status)->toBe(SubscriptionStatus::Trialing)
        ->and($abo->inTestphase())->toBeTrue()
        ->and(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeTrue();
});

it('sperrt kostenpflichtigen Versand bei leerem Kontingent', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    config()->set('mrs.billing.included.messages', 2);

    abrechnungsnachricht(MessageCostCategory::Utility);

    expect(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeTrue();

    abrechnungsnachricht(MessageCostCategory::Marketing);

    expect(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeFalse()
        ->and(app(Kontingente::class)->rest()['nachrichten'])->toBe(0);
});

it('gibt eine Aufstockung den Versand wieder frei', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    config()->set('mrs.billing.included.messages', 1);

    abrechnungsnachricht(MessageCostCategory::Utility);

    expect(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeFalse();

    app(Kontingente::class)->stockeAuf('nachrichten', 250);

    expect(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeTrue();
});

it('sperrt eine gekuendigte Praxis', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->status = SubscriptionStatus::Canceled;
    $abo->save();

    expect(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeFalse();
});

it('laesst bei offener Zahlung weiterarbeiten', function (): void {
    // Stripe mahnt mehrfach; wer beim ersten fehlgeschlagenen Einzug die
    // Kommunikation abschaltet, verliert einen Kunden wegen einer
    // abgelaufenen Karte.
    alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->status = SubscriptionStatus::PastDue;
    $abo->save();

    expect(app(Kontingente::class)->darfKostenpflichtigSenden())->toBeTrue();
});

/* Webhook ------------------------------------------------------------------ */

/**
 * @param  array<string, mixed>  $daten
 * @return array<string, string>
 */
function stripekopf(array $daten, string $geheimnis = 'whsec_test'): array
{
    $rumpf = (string) json_encode($daten);
    $zeit = time();

    return ['Stripe-Signature' => 't='.$zeit.',v1='.hash_hmac('sha256', $zeit.'.'.$rumpf, $geheimnis)];
}

it('verwirft eine Zustellung mit falscher Signatur', function (): void {
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    $daten = ['type' => 'customer.subscription.updated', 'data' => ['object' => ['customer' => 'cus_1']]];

    postJson(route('stripe.webhook'), $daten, ['Stripe-Signature' => 't='.time().',v1=falsch'])
        ->assertForbidden();
});

it('verwirft eine alte Zustellung', function (): void {
    // Eine aufgezeichnete Zustellung soll sich nicht Wochen spaeter erneut
    // abspielen lassen.
    $rumpf = '{"type":"ping"}';
    $alt = time() - 3600;

    expect(Stripesignatur::stimmt(
        $rumpf,
        't='.$alt.',v1='.hash_hmac('sha256', $alt.'.'.$rumpf, 'whsec_test'),
        'whsec_test',
    ))->toBeFalse();
});

it('uebernimmt den Zustand aus dem Webhook', function (): void {
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    $organisation = alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_1';
    $abo->save();

    ohneMandant();

    $daten = [
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_1',
            'customer' => 'cus_1',
            'status' => 'active',
            'current_period_start' => CarbonImmutable::now()->getTimestamp(),
            'current_period_end' => CarbonImmutable::now()->addMonth()->getTimestamp(),
        ]],
    ];

    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();

    alsMandant($organisation);

    $frisch = Subscription::query()->firstOrFail();

    expect($frisch->status)->toBe(SubscriptionStatus::Active)
        ->and($frisch->stripe_subscription_id)->toBe('sub_1')
        ->and($frisch->period_ends_at)->not->toBeNull();
});

it('bucht eine Aufstockung erst nach der Zahlung', function (): void {
    // Wer sie beim Oeffnen der Kasse gutschriebe, verschenkte Kontingent an
    // jeden, der sie wieder schliesst.
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    $organisation = alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_1';
    $abo->save();

    ohneMandant();

    $daten = [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['customer' => 'cus_1', 'mode' => 'payment', 'payment_status' => 'paid']],
    ];

    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();

    alsMandant($organisation);

    expect(Subscription::query()->firstOrFail()->extra_messages)
        ->toBe((int) config('mrs.billing.topup.messages'));
});

it('bucht nichts, solange nicht bezahlt ist', function (): void {
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    $organisation = alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_1';
    $abo->save();

    ohneMandant();

    $daten = [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['customer' => 'cus_1', 'mode' => 'payment', 'payment_status' => 'unpaid']],
    ];

    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();

    alsMandant($organisation);

    expect(Subscription::query()->firstOrFail()->extra_messages)->toBe(0);
});

it('raeumt Aufgestocktes zu Beginn einer neuen Periode ab', function (): void {
    // Wer im Januar aufstockt, hat das im Februar verbraucht -- sonst waechst
    // das Kontingent still von Monat zu Monat.
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    $organisation = alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_1';
    $abo->extra_messages = 500;
    $abo->period_starts_at = CarbonImmutable::now()->subMonth();
    $abo->save();

    ohneMandant();

    $daten = [
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_1',
            'customer' => 'cus_1',
            'status' => 'active',
            'current_period_start' => CarbonImmutable::now()->getTimestamp(),
            'current_period_end' => CarbonImmutable::now()->addMonth()->getTimestamp(),
        ]],
    ];

    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();

    alsMandant($organisation);

    expect(Subscription::query()->firstOrFail()->extra_messages)->toBe(0);
});

/* Anzeige ------------------------------------------------------------------ */

it('zeigt der Inhaberin Mengen statt Cent', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    abrechnungsnachricht(MessageCostCategory::Utility);
    agentenlauf();

    actingAs($inhaberin)
        ->get(route('abo.edit'))
        ->assertInertia(fn ($seite) => $seite
            ->component('settings/Abo')
            ->where('verbrauch.kostenpflichtig', 1)
            ->where('verbrauch.agentenlaeufe', 1)
            ->where('enthalten.nachrichten', (int) config('mrs.billing.included.messages'))
            ->where('testphase', true)
        );
});

it('laesst den Empfang nicht an das Abo', function (): void {
    // Wer Termine bucht, schliesst keine Vertraege.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $empfang = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($empfang)->get(route('abo.edit'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Nachtrag: Sperre nach der letzten Mahnung und Bildkontingent
|--------------------------------------------------------------------------
*/

it('laesst bei offener Zahlung alles offen', function (): void {
    // Stripe mahnt mehrfach. Wer beim ersten fehlgeschlagenen Einzug die
    // Praxis abschaltet, verliert einen Kunden wegen einer abgelaufenen
    // Karte.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    Subscription::query()->create(['status' => SubscriptionStatus::PastDue->value]);

    actingAs($inhaberin)->get(route('dashboard'))->assertOk();
});

it('sperrt den Zugang nach der letzten Mahnung', function (): void {
    // **Die Betreiberentscheidung**, die WP-06 offengelassen hatte: Stripes
    // Zustand `unpaid` heisst, alle Einzugsversuche sind gescheitert.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    Subscription::query()->create(['status' => SubscriptionStatus::Unpaid->value]);

    actingAs($inhaberin)
        ->get(route('dashboard'))
        ->assertRedirect(route('abo.edit'))
        ->assertSessionHas('fehler');
});

it('laesst den Weg aus der Sperre offen', function (): void {
    // Eine Sperre, aus der man nicht herauskommt, ohne hineinzukommen, ist
    // eine Falle.
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    Subscription::query()->create(['status' => SubscriptionStatus::Unpaid->value]);

    actingAs($inhaberin)->get(route('abo.edit'))->assertOk();
});

it('uebernimmt Stripes unpaid als Sperre, past_due aber nicht', function (): void {
    expect(SubscriptionStatus::ausStripe('unpaid'))->toBe(SubscriptionStatus::Unpaid)
        ->and(SubscriptionStatus::ausStripe('unpaid')->sperrtZugang())->toBeTrue()
        ->and(SubscriptionStatus::ausStripe('past_due'))->toBe(SubscriptionStatus::PastDue)
        ->and(SubscriptionStatus::ausStripe('past_due')->sperrtZugang())->toBeFalse();
});

it('schreibt beim Aufstocken nur gut, was gekauft wurde', function (): void {
    // **Bis WP-31 schrieb der Webhook nach jeder Zahlung beides gut** --
    // ungenau, aber folgenlos, solange beide Bloecke zusammen verkauft
    // wurden. Mit dem dritten Artikel waere daraus ein Fehler geworden: wer
    // Bilder kauft, bekaeme Nachrichten.
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    $organisation = alsMandant(organisation('Demo-Praxis'));

    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_1';
    $abo->save();

    ohneMandant();

    $daten = [
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'customer' => 'cus_1',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'metadata' => ['artikel' => 'bilder', 'menge' => '7'],
        ]],
    ];

    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();

    alsMandant($organisation);

    $frisch = Subscription::query()->firstOrFail();

    expect($frisch->extra_images)->toBe(7)
        ->and($frisch->extra_messages)->toBe(0)
        ->and($frisch->extra_agent_runs)->toBe(0);
});
