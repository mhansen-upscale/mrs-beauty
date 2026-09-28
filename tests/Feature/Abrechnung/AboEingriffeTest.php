<?php

declare(strict_types=1);

use App\Betrieb\Betriebslage;
use App\Enums\AuditEvent;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionChangeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\AboEingriffAusfuehren;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| WP-34c, Abnahmekriterien 12 bis 20 -- Eingriffe in das Abo
|--------------------------------------------------------------------------
|
| **Der Betreiber beauftragt, Stripe entscheidet, der Webhook berichtet.**
| Das Backoffice schreibt keinen Abo-Zustand; es legt einen Auftrag an, und
| der spricht mit Stripe -- nie im Anfragezyklus (Regel 4).
|
| **Ausser im Testbetrieb:** ohne Stripe-Schluessel gibt es niemanden, der
| berichten koennte. Dann wirkt der Eingriff sofort lokal und sagt das.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));

    config()->set('services.stripe.key', 'sk_test_eingriffe');
    config()->set('services.stripe.url', 'https://stripe.test');
    config()->set('services.stripe.webhook_secret', 'whsec_test');
    config()->set('services.stripe.free_month_coupon', 'gratis-monat');
});

/** Eine Praxis mit laufendem Stripe-Abo. */
function stripepraxis(): Organization
{
    $praxis = alsMandant(organisation('Demo-Praxis'));

    $abo = new Subscription;
    $abo->status = SubscriptionStatus::Active;
    $abo->stripe_customer_id = 'cus_1';
    $abo->stripe_subscription_id = 'sub_1';
    $abo->period_starts_at = CarbonImmutable::parse('2027-01-01 00:00:00');
    $abo->period_ends_at = CarbonImmutable::parse('2027-02-01 00:00:00');
    $abo->save();

    ohneMandant();

    return $praxis;
}

/**
 * Ein Eingriff aus dem Mandantenblatt.
 *
 * @param  array<string, mixed>  $mehr
 * @return TestResponse<Response>
 */
function eingriff(Organization $praxis, string $aktion, array $mehr = [], ?User $wer = null): TestResponse
{
    return actingAs($wer ?? User::factory()->superAdmin()->create())
        ->post(route('backoffice.abo', ['organisation' => $praxis->uuid]), [
            'aktion' => $aktion,
            'grund' => 'Umbau der Praxis bis März',
            'current_password' => 'password',
            ...$mehr,
        ]);
}

function aboVon(Organization $praxis): Subscription
{
    return app(TenantContext::class)->runAs($praxis, fn (): Subscription => Subscription::query()->firstOrFail());
}

function eingriffVon(Organization $praxis): SubscriptionChange
{
    return app(TenantContext::class)->runAs($praxis, fn (): SubscriptionChange => SubscriptionChange::query()->latest()->firstOrFail());
}

/**
 * Eine Zustellung `customer.subscription.updated` fuer sub_1.
 *
 * @param  array<string, mixed>  $objekt
 */
function aboZustellung(array $objekt, int $zeitpunkt, string $art = 'customer.subscription.updated'): void
{
    $daten = [
        'id' => 'evt_'.$zeitpunkt.'_'.$art,
        'type' => $art,
        'created' => $zeitpunkt,
        'data' => ['object' => [
            'id' => 'sub_1',
            'customer' => 'cus_1',
            'status' => 'active',
            'current_period_start' => CarbonImmutable::parse('2027-01-01 00:00:00')->getTimestamp(),
            'current_period_end' => CarbonImmutable::parse('2027-02-01 00:00:00')->getTimestamp(),
            ...$objekt,
        ]],
    ];

    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();
}

/* Auftrag statt Anfragezyklus ---------------------------------------------- */

it('beauftragt eine Pause, ohne Stripe im Anfragezyklus zu rufen', function (): void {
    Queue::fake();
    Http::fake();

    $praxis = stripepraxis();

    eingriff($praxis, 'pause')->assertSessionHasNoErrors();

    Http::assertNothingSent();
    Queue::assertPushed(AboEingriffAusfuehren::class);

    expect(eingriffVon($praxis)->status)->toBe(SubscriptionChangeStatus::Pending);
});

it('schickt die Pause mit Idempotenzschluessel und fester API-Version', function (): void {
    Http::fake(['stripe.test/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'active'])]);

    $praxis = stripepraxis();

    eingriff($praxis, 'pause', ['bis' => '2027-03-01'])->assertSessionHasNoErrors();

    $auftrag = eingriffVon($praxis);

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/subscriptions/sub_1')
        && $anfrage->method() === 'POST'
        && ($anfrage->data()['pause_collection[behavior]'] ?? null) === 'void'
        && ($anfrage->data()['pause_collection[resumes_at]'] ?? null) === CarbonImmutable::parse('2027-03-01 00:00:00')->getTimestamp()
        && $anfrage->hasHeader('Idempotency-Key', $auftrag->idempotency_key)
        && $anfrage->hasHeader('Stripe-Version', (string) config('services.stripe.api_version')));

    // **Beauftragt, nicht erledigt**: den Zustand meldet der Webhook.
    expect($auftrag->status)->toBe(SubscriptionChangeStatus::Done)
        ->and(aboVon($praxis)->zugang())->toBe(SubscriptionAccess::Open);
});

it('sperrt nach dem Webhook mit Pause und oeffnet nach dem ohne', function (): void {
    $praxis = stripepraxis();

    aboZustellung(['pause_collection' => ['behavior' => 'void', 'resumes_at' => null]], 1000);

    expect(aboVon($praxis)->zugang())->toBe(SubscriptionAccess::Paused)
        ->and(aboVon($praxis)->paused_at)->not->toBeNull();

    aboZustellung(['pause_collection' => null], 2000);

    expect(aboVon($praxis)->zugang())->toBe(SubscriptionAccess::Open);
});

it('kuendigt zum Periodenende und nimmt die Kuendigung zurueck', function (): void {
    Http::fake(['stripe.test/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1'])]);

    $praxis = stripepraxis();

    eingriff($praxis, 'cancel_period_end')->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $anfrage): bool => ($anfrage->data()['cancel_at_period_end'] ?? null) === 'true');

    aboZustellung(['cancel_at_period_end' => true, 'cancel_at' => CarbonImmutable::parse('2027-02-01 00:00:00')->getTimestamp()], 1000);

    // Bis zum Ende offen -- bezahlt ist bezahlt.
    expect(aboVon($praxis)->zugang())->toBe(SubscriptionAccess::Open)
        ->and(aboVon($praxis)->cancel_at_period_end)->toBeTrue();

    eingriff($praxis, 'revoke_cancel')->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $anfrage): bool => ($anfrage->data()['cancel_at_period_end'] ?? null) === 'false');
});

it('kuendigt sofort und sperrt nach dem Webhook', function (): void {
    Http::fake(['stripe.test/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'canceled'])]);

    $praxis = stripepraxis();

    eingriff($praxis, 'cancel_now')->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $anfrage): bool => $anfrage->method() === 'DELETE' && str_ends_with($anfrage->url(), '/v1/subscriptions/sub_1'));

    aboZustellung(['status' => 'canceled'], 1000, 'customer.subscription.deleted');

    expect(aboVon($praxis)->zugang())->toBe(SubscriptionAccess::Canceled);
});

it('schenkt einen Monat ueber den Gutschein', function (): void {
    Http::fake([
        'stripe.test/v1/coupons/gratis-monat' => Http::response(['id' => 'gratis-monat']),
        'stripe.test/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1']),
    ]);

    $praxis = stripepraxis();

    eingriff($praxis, 'free_month')->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $anfrage): bool => ($anfrage->data()['discounts[0][coupon]'] ?? null) === 'gratis-monat');

    // Den Gutschein gibt es schon -- es wird keiner angelegt.
    Http::assertNotSent(fn (Request $anfrage): bool => $anfrage->method() === 'POST' && str_ends_with($anfrage->url(), '/v1/coupons'));

    // Ein Gutschein fuer einmal traegt kein Enddatum -- er gilt fuer die
    // naechste Rechnung, also die am Periodenende.
    aboZustellung(['discount' => ['coupon' => ['id' => 'gratis-monat', 'duration' => 'once'], 'end' => null]], 1000);

    expect(aboVon($praxis)->discount_ends_at?->toDateString())->toBe('2027-02-01');
});

it('legt den Gutschein selbst an, wenn es ihn bei Stripe noch nicht gibt', function (): void {
    // **Nur noch Schluessel eintragen** (28.09.2026). Bis hier musste der
    // Gutschein von Hand im Dashboard entstehen und seine Kennung in die
    // Umgebung; ohne sie lehnte das Mandantenblatt den Gratismonat ab.
    config()->set('services.stripe.free_month_coupon', 'mrs_gratismonat');

    Http::fake([
        'stripe.test/v1/coupons/mrs_gratismonat' => Http::response(['error' => ['code' => 'resource_missing', 'message' => 'No such coupon']], 404),
        'stripe.test/v1/coupons' => Http::response(['id' => 'mrs_gratismonat']),
        'stripe.test/v1/subscriptions/sub_1' => Http::response(['id' => 'sub_1']),
    ]);

    $praxis = stripepraxis();

    eingriff($praxis, 'free_month')->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $anfrage): bool => $anfrage->method() === 'POST'
        && str_ends_with($anfrage->url(), '/v1/coupons')
        && $anfrage->hasHeader('Idempotency-Key')
        && ($anfrage->data()['id'] ?? null) === 'mrs_gratismonat'
        && (string) ($anfrage->data()['percent_off'] ?? '') === '100'
        && ($anfrage->data()['duration'] ?? null) === 'once');

    Http::assertSent(fn (Request $anfrage): bool => ($anfrage->data()['discounts[0][coupon]'] ?? null) === 'mrs_gratismonat');

    expect(eingriffVon($praxis)->status)->toBe(SubscriptionChangeStatus::Done);
});

/* Fehlschlaege ------------------------------------------------------------- */

it('macht einen endgueltigen Fehlschlag sichtbar, nicht nur im Log', function (): void {
    Http::fake(['stripe.test/*' => Http::response(['error' => ['message' => 'No such subscription: sub_1']], 400)]);

    $praxis = stripepraxis();

    eingriff($praxis, 'pause')->assertSessionHasNoErrors();

    $auftrag = eingriffVon($praxis);

    expect($auftrag->status)->toBe(SubscriptionChangeStatus::Failed)
        ->and($auftrag->error)->toContain('No such subscription');

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('backoffice.show', ['organisation' => $praxis->uuid]))
        ->assertInertia(fn ($seite) => $seite->where('mandant.aboStand.eingriffe.0.status', SubscriptionChangeStatus::Failed->value));

    expect(app(Betriebslage::class)->fuerInstallation()['gescheiterteAboEingriffe'])->toBe(1)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::SubscriptionChangeFailed->value)->count())->toBe(1);
});

it('wiederholt, wenn Stripe gerade nicht antwortet', function (): void {
    Queue::fake();
    Http::fake(['stripe.test/*' => Http::response([], 503)]);

    $praxis = stripepraxis();

    eingriff($praxis, 'pause');

    $auftrag = eingriffVon($praxis);

    // Der Auftrag wirft, damit die Warteschlange es erneut versucht -- und
    // bleibt bis dahin beauftragt.
    expect(fn () => app()->call([new AboEingriffAusfuehren((string) $praxis->uuid, (string) $auftrag->uuid), 'handle']))
        ->toThrow(RuntimeException::class);

    expect(eingriffVon($praxis)->status)->toBe(SubscriptionChangeStatus::Pending);
});

/* Grenzen ------------------------------------------------------------------ */

it('laesst Customer Success und Finanzen nicht in das Abo eingreifen', function (): void {
    Queue::fake();
    $praxis = stripepraxis();

    eingriff($praxis, 'pause', wer: User::factory()->customerSuccess()->create())->assertForbidden();
    eingriff($praxis, 'cancel_now', wer: User::factory()->finanzen()->create())->assertForbidden();

    Queue::assertNothingPushed();
});

it('beauftragt nichts ohne das eigene Passwort', function (): void {
    Queue::fake();
    $praxis = stripepraxis();

    eingriff($praxis, 'pause', ['current_password' => 'falsch'])->assertSessionHasErrors('current_password');

    Queue::assertNothingPushed();

    app(TenantContext::class)->runAs($praxis, fn () => expect(SubscriptionChange::query()->count())->toBe(0));
});

it('protokolliert jeden Eingriff mit Begruendung und Namen bei der Praxis', function (): void {
    Queue::fake();
    $praxis = stripepraxis();
    $betreiber = User::factory()->superAdmin()->create(['name' => 'Bea Betrieb']);

    eingriff($praxis, 'pause', wer: $betreiber);

    alsMandant($praxis);

    $eintrag = AuditLog::query()->where('event', AuditEvent::SubscriptionChangeRequested->value)->firstOrFail();

    expect($eintrag->reason)->toBe('Umbau der Praxis bis März')
        ->and($eintrag->actor_label)->toBe('Bea Betrieb')
        ->and($eintrag->context)->toMatchArray(['aktion' => 'pause']);
});

/* Testbetrieb ohne Stripe -------------------------------------------------- */

it('wirkt ohne Stripe sofort lokal -- und sagt es', function (): void {
    config()->set('services.stripe.key', null);
    Http::fake();

    $praxis = alsMandant(organisation('Ohne Stripe'));
    $abo = new Subscription;
    $abo->status = SubscriptionStatus::Active;
    $abo->save();
    ohneMandant();

    eingriff($praxis, 'pause')->assertSessionHasNoErrors();

    expect(aboVon($praxis)->zugang())->toBe(SubscriptionAccess::Paused)
        ->and(eingriffVon($praxis)->status)->toBe(SubscriptionChangeStatus::Done)
        ->and(eingriffVon($praxis)->parameters)->toMatchArray(['ohne_stripe' => true]);

    eingriff($praxis, 'resume')->assertSessionHasNoErrors();
    expect(aboVon($praxis)->zugang())->toBe(SubscriptionAccess::Open);

    eingriff($praxis, 'free_month')->assertSessionHasNoErrors();
    expect(aboVon($praxis)->discount_ends_at)->not->toBeNull();

    eingriff($praxis, 'cancel_now')->assertSessionHasNoErrors();
    expect(aboVon($praxis)->zugang())->toBe(SubscriptionAccess::Canceled);

    Http::assertNothingSent();
});
