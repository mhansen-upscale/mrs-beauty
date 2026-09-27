<?php

declare(strict_types=1);

use App\Enums\AgentAction;
use App\Enums\AuditEvent;
use App\Enums\BookingChannel;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SubscriptionStatus;
use App\Kanaele\Konversationen;
use App\Models\AgentRun;
use App\Models\ChannelIdentity;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Termine\Terminplaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\WhatsAppAufbau;
use Tests\Feature\Termine\Szenario;

/*
|--------------------------------------------------------------------------
| WP-34 -- Kennzahlen der Installation
|--------------------------------------------------------------------------
|
| Der Betreiber gehoert zu keiner Praxis; sein Dashboard war leer. Jetzt
| zeigt es, wie es um die Installation steht: Abos, Nutzung des Monats,
| Betrieb. Das Backoffice bleibt die Liste der Praxen.
|
| **Gezaehlt, nie gelesen** -- wie alles im Backoffice. Und **Summen ueber
| alle Praxen**, keine Zahl je Person.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function kennzahlenbetreiber(): User
{
    return User::factory()->superAdmin()->create();
}

/**
 * Eine Praxis mit einem Abo im gegebenen Zustand -- oder ohne Abo-Zeile.
 *
 * @param  array<string, mixed>  $felder
 */
function praxisMitAbo(string $name, ?SubscriptionStatus $zustand, array $felder = []): Organization
{
    $praxis = alsMandant(organisation($name));

    if ($zustand instanceof SubscriptionStatus) {
        $abo = new Subscription;
        $abo->status = $zustand;

        foreach ($felder as $feld => $wert) {
            $abo->setAttribute($feld, $wert);
        }

        $abo->save();
    }

    ohneMandant();

    return $praxis;
}

/** Eine ausgehende Nachricht der laufenden Praxis. */
function kennzahlnachricht(?MessageCostCategory $kategorie): Message
{
    $identitaet = ChannelIdentity::query()->firstOr(fn () => ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
    ]));

    $nachricht = new Message;
    $nachricht->conversation_id = app(Konversationen::class)->fuer($identitaet)->getKey();
    $nachricht->channel = ChannelType::WhatsApp;
    $nachricht->direction = MessageDirection::Outbound;
    $nachricht->status = MessageStatus::Sent;
    $nachricht->body = 'Hallo';
    $nachricht->cost_category = $kategorie;
    $nachricht->save();

    return $nachricht;
}

/** Ein Assistenzlauf der laufenden Praxis mit seinen Kosten in Zehntel-US-Cent. */
function kennzahllauf(int $kosten): AgentRun
{
    $identitaet = ChannelIdentity::query()->firstOr(fn () => ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
    ]));

    $lauf = new AgentRun;
    $lauf->conversation_id = app(Konversationen::class)->fuer($identitaet)->getKey();
    $lauf->action = AgentAction::Suggested;
    $lauf->cost_tenth_cents = $kosten;
    $lauf->save();

    return $lauf;
}

it('zeigt dem Betreiber unter Dashboard die Installation', function (): void {
    // Er gehoert zu keiner Praxis -- das Dashboard einer Praxis hat ihm
    // nichts zu zeigen, das der Installation schon.
    alsMandant(organisation('Nord'));
    alsMandant(organisation('Sued'));
    ohneMandant();

    actingAs(kennzahlenbetreiber())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->component('DashboardBetreiber')
            ->where('kennzahlen.betrieb.mandanten', 2)
            ->where('warnungTage', (int) config('mrs.backoffice.testphase_warnung_tage'))
        );
});

it('zeigt im Backoffice nur die Liste der Praxen', function (): void {
    alsMandant(organisation('Nord'));
    ohneMandant();

    actingAs(kennzahlenbetreiber())
        ->get(route('backoffice.index'))
        ->assertInertia(fn ($seite) => $seite
            ->component('backoffice/Index')
            ->has('mandanten', 1)
            ->missing('kennzahlen')
        );
});

it('zaehlt die Abos der Installation', function (): void {
    praxisMitAbo('Aktiv', SubscriptionStatus::Active, ['stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1']);
    praxisMitAbo('Zahlung offen', SubscriptionStatus::PastDue, ['stripe_customer_id' => 'cus_2', 'stripe_subscription_id' => 'sub_2']);
    praxisMitAbo('Endet bald', SubscriptionStatus::Trialing, ['trial_ends_at' => CarbonImmutable::now()->addDays(3)]);
    praxisMitAbo('Endet spaeter', SubscriptionStatus::Trialing, ['trial_ends_at' => CarbonImmutable::now()->addDays(20)]);
    praxisMitAbo('Unbezahlt', SubscriptionStatus::Unpaid, ['stripe_customer_id' => 'cus_3', 'stripe_subscription_id' => 'sub_3']);
    praxisMitAbo('Gekuendigt', SubscriptionStatus::Canceled);

    // **Ohne Abo-Zeile ist eine Praxis in der Testphase** (WP-06) -- und die
    // endet, gerechnet ab dem Anlegen der Praxis.
    $alt = praxisMitAbo('Abgelaufen', null);
    $alt->forceFill(['created_at' => CarbonImmutable::now()->subDays(40)])->save();

    $gesperrt = praxisMitAbo('Gesperrt', null);
    $gesperrt->suspended_at = CarbonImmutable::now();
    $gesperrt->save();

    actingAs(kennzahlenbetreiber())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->component('DashboardBetreiber')
            ->where('kennzahlen.praxen.gesamt', 8)
            ->where('kennzahlen.praxen.gesperrt', 1)
            ->where('kennzahlen.praxen.neu', 7)
            ->where('kennzahlen.abos.zahlend', 2)
            ->where('kennzahlen.abos.mrrCent', 2 * (int) config('mrs.billing.prices.base_cents'))
            ->where('kennzahlen.abos.zahlungOffen', 1)
            ->where('kennzahlen.abos.unbezahlt', 1)
            ->where('kennzahlen.abos.gekuendigt', 1)
            // Laufend: endet bald, endet spaeter und die frisch angelegte,
            // gesperrte Praxis ohne Abo.
            ->where('kennzahlen.abos.testphase', 3)
            ->where('kennzahlen.abos.testphaseEndetBald', 1)
            ->where('kennzahlen.abos.testphaseAbgelaufen', 1)
        );
});

it('zaehlt die Nutzung des Monats ueber alle Praxen', function (): void {
    alsMandant(organisation('Nord'));
    kennzahlnachricht(MessageCostCategory::Utility);
    kennzahlnachricht(MessageCostCategory::Marketing);
    kennzahlnachricht(MessageCostCategory::Service);
    kennzahllauf(120);

    $szenario = new Szenario;
    app(Terminplaner::class)->buche(
        $szenario->vorschlag(),
        $szenario->kontakt,
        kanal: BookingChannel::Public,
        jetzt: $szenario->jetzt(),
    );

    alsMandant(organisation('Sued'));
    kennzahlnachricht(MessageCostCategory::Utility);
    kennzahlnachricht(null);
    kennzahllauf(300);

    ohneMandant();

    actingAs(kennzahlenbetreiber())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            // Templates zaehlen, Antworten im Fenster und Unbekanntes nicht
            // -- dieselbe Regel wie im Kontingent (B12).
            ->where('kennzahlen.monat.kostenpflichtig', 3)
            ->where('kennzahlen.monat.agentenlaeufe', 2)
            // 420 Zehntel-US-Cent sind 42 US-Cent.
            ->where('kennzahlen.monat.modellkostenUsdCent', 42)
            ->where('kennzahlen.monat.termine', 1)
            ->where('kennzahlen.monat.selbstGebucht', 1)
        );
});

it('zaehlt eine gestoerte Praxis einmal', function (): void {
    $gestoert = alsMandant(organisation('Gestoert'));
    (new WhatsAppAufbau($gestoert))->verbindung->meldeAusfall(ConnectionStatus::Expired, 'token_invalid');

    $gesund = alsMandant(organisation('Gesund'));
    new WhatsAppAufbau($gesund);

    ohneMandant();

    actingAs(kennzahlenbetreiber())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.betrieb.praxenMitStoerung', 1));
});

it('liest die Kennzahlen in einem begruendeten Querzugriff', function (): void {
    alsMandant(organisation('Nord'));
    alsMandant(organisation('Sued'));
    ohneMandant();

    actingAs(kennzahlenbetreiber())->get(route('dashboard'))->assertOk();

    // **Einer, nicht einer je Praxis** -- und mit Begruendung (Regel 1).
    expect(DB::table('audit_logs')
        ->where('event', AuditEvent::CrossTenantAccess->value)
        ->where('reason', 'like', '%Kennzahlen seiner Installation%')
        ->count())->toBe(1);
});
