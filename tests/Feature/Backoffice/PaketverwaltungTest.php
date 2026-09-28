<?php

declare(strict_types=1);

use App\Abrechnung\Kontingente;
use App\Abrechnung\Paket;
use App\Betrieb\Betriebslage;
use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Jobs\AboAufFassungUmstellen;
use App\Jobs\PaketfassungAnlegen;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| WP-06b -- Paketverwaltung (B20)
|--------------------------------------------------------------------------
|
| **Ein Preis bei Stripe ist unveraenderlich. Also ist es das Paket auch.**
| Speichern legt eine Fassung an; jedes Abo zeigt auf die Fassung seines
| Abschlusses. Ob der Bestand mitgeht, entscheidet der Betreiber je Aenderung.
|
| **Testbetrieb:** ohne Stripe-Schluessel gilt eine Fassung sofort, und eine
| Umstellung des Bestands auch -- es gibt niemanden, der berichten koennte.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));

    config()->set('services.stripe.key', null);
    config()->set('services.stripe.url', 'https://stripe.test');
    config()->set('services.stripe.webhook_secret', 'whsec_test');
});

function mitStripe(): void
{
    config()->set('services.stripe.key', 'sk_test_paket');
}

/**
 * Was das Formular schickt -- in Euro, wo es Geld ist.
 *
 * @param  array<string, mixed>  $mehr
 * @return array<string, mixed>
 */
function paketFormular(array $mehr = []): array
{
    return [
        'name' => 'Mrs. Beauty Praxis',
        'grundpreis' => '890.00',
        'einrichtung' => '490.00',
        'aufstockung' => '49.00',
        'bildpreis' => '2.50',
        'nachrichten' => 500,
        'agentenlaeufe' => 700,
        'bilder' => 40,
        'blockNachrichten' => 250,
        'blockAgentenlaeufe' => 300,
        'testphaseTage' => 21,
        'bestand' => false,
        'grund' => 'Preisanpassung 2027',
        'current_password' => 'password',
        ...$mehr,
    ];
}

/**
 * @param  array<string, mixed>  $mehr
 * @return TestResponse<Response>
 */
function paketSpeichern(array $mehr = [], ?User $wer = null): TestResponse
{
    return actingAs($wer ?? User::factory()->superAdmin()->create())
        ->post(route('backoffice.paket.store'), paketFormular($mehr));
}

/** Eine Fassung mit Preisen bei Stripe -- die Ausgangslage im Stripe-Betrieb. */
function paketMitStripe(): PlanVersion
{
    return neuesPaket([
        'stripe_price_base' => 'price_basis_1',
        'stripe_price_setup' => 'price_einrichtung_1',
        'stripe_price_topup' => 'price_block_1',
        'stripe_price_image' => 'price_bild_1',
        'base_cents' => 79000,
        'included_messages' => 400,
    ]);
}

/** Eine Praxis mit laufendem Abo bei Stripe, auf dieser Fassung. */
function paketpraxis(PlanVersion $fassung, string $name = 'Demo-Praxis'): Organization
{
    $praxis = alsMandant(organisation($name));

    $abo = new Subscription;
    $abo->status = SubscriptionStatus::Active;
    $abo->stripe_customer_id = 'cus_'.Str::slug($name);
    $abo->stripe_subscription_id = 'sub_'.Str::slug($name);
    $abo->plan_version_id = $fassung->getKey();
    $abo->period_starts_at = CarbonImmutable::parse('2027-01-01 00:00:00');
    $abo->period_ends_at = CarbonImmutable::parse('2027-02-01 00:00:00');
    $abo->save();

    ohneMandant();

    return $praxis;
}

function paketabo(Organization $praxis): Subscription
{
    return app(TenantContext::class)->runAs($praxis, fn (): Subscription => Subscription::query()->firstOrFail());
}

function fassungNr(int $nummer): PlanVersion
{
    return PlanVersion::query()->where('number', $nummer)->firstOrFail();
}

/**
 * Stripe als Attrappe: Preise, Produkt, Abo. Meldet je Abo den Preis, auf
 * den zuletzt umgestellt wurde.
 *
 * **Einmal je Test.** Ein zweites Http::fake() stellt sich hinten an und
 * kaeme nie zum Zug; was scheitert, steht deshalb in `test.stripe_scheitert`
 * (Pfad => Status) und laesst sich im Test umstellen.
 *
 * @param  array<string, int>  $scheitert
 */
function stripeAttrappe(array $scheitert = []): void
{
    /** @var array<string, string> $aboPreise */
    $aboPreise = [];
    $zaehler = 0;

    config()->set('test.stripe_scheitert', $scheitert);

    Http::fake(function (Request $anfrage) use (&$aboPreise, &$zaehler) {
        $pfad = Str::after(parse_url($anfrage->url(), PHP_URL_PATH) ?: '', '/v1/');

        /** @var array<string, int> $scheitert */
        $scheitert = config('test.stripe_scheitert', []);

        foreach ($scheitert as $muster => $status) {
            if ($pfad === $muster && $anfrage->method() === 'POST') {
                return Http::response(['error' => ['message' => 'Abgelehnt von Stripe', 'type' => 'invalid_request_error']], $status);
            }
        }

        if ($pfad === 'prices' && $anfrage->method() === 'POST') {
            $zaehler++;

            return Http::response(['id' => 'price_neu_'.$anfrage->data()['unit_amount'].'_'.$zaehler]);
        }

        if (str_starts_with($pfad, 'prices/') && $anfrage->method() === 'GET') {
            return Http::response(['id' => Str::after($pfad, 'prices/'), 'product' => 'prod_paket']);
        }

        $abo = Str::after($pfad, 'subscriptions/');

        if (str_starts_with($pfad, 'subscriptions/') && $anfrage->method() === 'GET') {
            return Http::response(['id' => $abo, 'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => $aboPreise[$abo] ?? 'price_basis_1']]]]]);
        }

        if (str_starts_with($pfad, 'subscriptions/') && $anfrage->method() === 'POST') {
            $aboPreise[$abo] = (string) ($anfrage->data()['items[0][price]'] ?? 'price_basis_1');
        }

        return Http::response(['id' => 'ok']);
    });
}

/** @return array<int, Request> */
function stripeAufrufe(string $methode, string $pfad): array
{
    return Http::recorded(fn (Request $anfrage): bool => $anfrage->method() === $methode
        && Str::after(parse_url($anfrage->url(), PHP_URL_PATH) ?: '', '/v1/') === $pfad)
        ->map(fn (array $paar): Request => $paar[0])
        ->values()
        ->all();
}

/**
 * `customer.subscription.updated` mit dem Preis an der Position.
 */
function paketZustellung(Organization $praxis, string $preis, string $beginn, string $ende, int $zeitpunkt): void
{
    $abo = paketabo($praxis);

    // Stripe meldet sich ohne Sitzung -- nicht als der Betreiber, der vorher
    // gespeichert hat (und nach Tagen im Leerlauf abgemeldet waere).
    Auth::forgetUser();

    $daten = [
        'id' => 'evt_'.$zeitpunkt,
        'type' => 'customer.subscription.updated',
        'created' => $zeitpunkt,
        'data' => ['object' => [
            'id' => $abo->stripe_subscription_id,
            'customer' => $abo->stripe_customer_id,
            'status' => 'active',
            'current_period_start' => CarbonImmutable::parse($beginn)->getTimestamp(),
            'current_period_end' => CarbonImmutable::parse($ende)->getTimestamp(),
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => $preis]]]],
        ]],
    ];

    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();
}

/* Fassungen ---------------------------------------------------------------- */

it('legt beim Speichern eine neue Fassung an und laesst die vorige, wie sie war', function (): void {
    $vorher = app(Paket::class)->aktuell();
    $werte = $vorher->getAttributes();

    paketSpeichern()->assertRedirect()->assertSessionHasNoErrors();

    $neu = app(Paket::class)->aktuell();

    expect($neu->number)->toBe($vorher->number + 1)
        ->and($neu->base_cents)->toBe(89000)
        ->and($neu->setup_cents)->toBe(49000)
        ->and($neu->image_price_cents)->toBe(250)
        ->and($neu->included_agent_runs)->toBe(700)
        ->and($neu->trial_days)->toBe(21)
        ->and($neu->reason)->toBe('Preisanpassung 2027')
        ->and($vorher->fresh()?->getAttributes())->toBe($werte);
});

it('haelt eine Fassung fest -- in Eloquent und in der Datenbank', function (): void {
    $fassung = app(Paket::class)->aktuell();

    expect(fn () => $fassung->forceFill(['base_cents' => 1])->save())->toThrow(RuntimeException::class)
        ->and(fn () => $fassung->delete())->toThrow(RuntimeException::class)
        ->and(fn () => DB::table('plan_versions')->where('id', $fassung->getKey())->update(['base_cents' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('plan_versions')->where('id', $fassung->getKey())->update(['stripe_price_base' => 'price_x']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('plan_versions')->where('id', $fassung->getKey())->delete())->toThrow(QueryException::class);

    // Vor `ready` darf der Auftrag die Stripe-Felder nachtragen -- sonst nichts.
    $offen = new PlanVersion;
    $offen->forceFill([
        ...collect($fassung->getAttributes())->except(['id', 'number', 'activated_at', 'created_at', 'updated_at'])->all(),
        'number' => $fassung->number + 1,
        'stripe_state' => PlanVersion::AUSSTEHEND,
    ])->save();

    DB::table('plan_versions')->where('id', $offen->getKey())->update(['stripe_price_base' => 'price_neu']);

    expect($offen->fresh()?->stripe_price_base)->toBe('price_neu')
        ->and(fn () => DB::table('plan_versions')->where('id', $offen->getKey())->update(['topup_cents' => 1]))->toThrow(QueryException::class)
        ->and(fn () => $offen->forceFill(['name' => 'Anders'])->save())->toThrow(RuntimeException::class);
});

it('weist negative Preise, einen Grundpreis von null und leere Kontingente am Feld ab', function (array $eingabe, string $feld): void {
    $vorher = PlanVersion::query()->count();

    paketSpeichern($eingabe)->assertSessionHasErrors($feld);

    expect(PlanVersion::query()->count())->toBe($vorher);
})->with([
    'Grundpreis null' => [['grundpreis' => '0'], 'grundpreis'],
    'negative Einrichtung' => [['einrichtung' => '-1'], 'einrichtung'],
    'negativer Bildpreis' => [['bildpreis' => '-2.50'], 'bildpreis'],
    'Cent-Bruchteil' => [['grundpreis' => '790.005'], 'grundpreis'],
    'keine Nachrichten' => [['nachrichten' => 0], 'nachrichten'],
    'keine Assistenzlaeufe' => [['agentenlaeufe' => 0], 'agentenlaeufe'],
    'leerer Block' => [['blockNachrichten' => 0], 'blockNachrichten'],
    'keine Testphase' => [['testphaseTage' => 0], 'testphaseTage'],
]);

it('legt keine Fassung an, wenn sich nichts geaendert hat', function (): void {
    $aktuell = app(Paket::class)->aktuell();

    paketSpeichern([
        'name' => $aktuell->name,
        'grundpreis' => number_format($aktuell->base_cents / 100, 2, '.', ''),
        'einrichtung' => number_format($aktuell->setup_cents / 100, 2, '.', ''),
        'aufstockung' => number_format($aktuell->topup_cents / 100, 2, '.', ''),
        'bildpreis' => number_format($aktuell->image_price_cents / 100, 2, '.', ''),
        'nachrichten' => $aktuell->included_messages,
        'agentenlaeufe' => $aktuell->included_agent_runs,
        'bilder' => $aktuell->included_images,
        'blockNachrichten' => $aktuell->topup_messages,
        'blockAgentenlaeufe' => $aktuell->topup_agent_runs,
        'testphaseTage' => $aktuell->trial_days,
    ])->assertSessionHasErrors('name');

    expect(app(Paket::class)->aktuell()->number)->toBe($aktuell->number);
});

it('legt nach dem Testbetrieb die Preise auch fuer eine unveraenderte Fassung an', function (): void {
    // Fassung 1 aus dem Testbetrieb: ohne Preise bei Stripe.
    $aktuell = neuesPaket(['stripe_price_base' => null, 'stripe_price_setup' => null, 'stripe_price_topup' => null, 'stripe_price_image' => null]);
    mitStripe();
    Queue::fake();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('backoffice.paket'))
        ->assertInertia(fn (Assert $seite) => $seite->where('ohneStripePreise', true));

    paketSpeichern([
        'name' => $aktuell->name,
        'grundpreis' => number_format($aktuell->base_cents / 100, 2, '.', ''),
        'einrichtung' => number_format($aktuell->setup_cents / 100, 2, '.', ''),
        'aufstockung' => number_format($aktuell->topup_cents / 100, 2, '.', ''),
        'bildpreis' => number_format($aktuell->image_price_cents / 100, 2, '.', ''),
        'nachrichten' => $aktuell->included_messages,
        'agentenlaeufe' => $aktuell->included_agent_runs,
        'bilder' => $aktuell->included_images,
        'blockNachrichten' => $aktuell->topup_messages,
        'blockAgentenlaeufe' => $aktuell->topup_agent_runs,
        'testphaseTage' => $aktuell->trial_days,
    ])->assertSessionHasNoErrors();

    Queue::assertPushed(PaketfassungAnlegen::class);
});

/* Testbetrieb ------------------------------------------------------------- */

it('laesst im Testbetrieb eine Fassung sofort gelten, ohne Stripe', function (): void {
    Http::fake();

    paketSpeichern()->assertSessionHasNoErrors();

    $neu = app(Paket::class)->aktuell();

    expect($neu->base_cents)->toBe(89000)
        ->and($neu->stripe_state)->toBe(PlanVersion::BEREIT)
        ->and($neu->hatStripePreise())->toBeFalse();

    Http::assertNothingSent();

    $protokoll = AuditLog::query()->withoutGlobalScopes()->whereNull('organization_id')->pluck('event')->all();

    expect($protokoll)->toContain(AuditEvent::PlanVersionCreated)
        ->and($protokoll)->toContain(AuditEvent::PlanVersionReady);
});

it('stellt im Testbetrieb den Bestand sofort um, wenn so gewaehlt', function (): void {
    $alt = app(Paket::class)->aktuell();
    $praxis = paketpraxis($alt);

    paketSpeichern(['bestand' => true])->assertSessionHasNoErrors();

    $neu = app(Paket::class)->aktuell();

    expect(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe($neu->getKey())
        ->and(app(TenantContext::class)->runAs($praxis, fn () => app(Kontingente::class)->enthalten()['nachrichten']))->toBe(500)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('organization_id', $praxis->getKey())->where('event', AuditEvent::SubscriptionPlanChanged->value)->exists())->toBeTrue();
});

/* Stripe ------------------------------------------------------------------- */

it('ruft Stripe beim Speichern nicht im Anfragezyklus auf', function (): void {
    mitStripe();
    paketMitStripe();
    Queue::fake();
    Http::fake();

    paketSpeichern()->assertSessionHasNoErrors();

    Queue::assertPushed(PaketfassungAnlegen::class);
    Http::assertNothingSent();

    expect(PlanVersion::query()->orderByDesc('number')->firstOrFail()->stripe_state)->toBe(PlanVersion::AUSSTEHEND);
});

it('legt vier Preise unter dem bisherigen Produkt an -- und bei einer Wiederholung keinen fuenften', function (): void {
    mitStripe();
    paketMitStripe();
    Queue::fake();

    paketSpeichern(['name' => 'Mrs. Beauty 2027'])->assertSessionHasNoErrors();

    $neu = PlanVersion::query()->orderByDesc('number')->firstOrFail();

    // Der erste Versuch bricht beim vierten Preis ab -- ein Ausfall, auch
    // nach den Wiederholungen des Clients.
    //
    // Die erste Attrappe, die antwortet, gewinnt: die drei ersten Preise
    // gehen durch, danach antwortet die allgemeine -- zuerst mit 503.
    $gezaehlt = 0;
    Http::fake(function (Request $anfrage) use (&$gezaehlt) {
        $pfad = Str::after(parse_url($anfrage->url(), PHP_URL_PATH) ?: '', '/v1/');

        if ($pfad === 'prices' && $anfrage->method() === 'POST' && ++$gezaehlt <= 3) {
            return Http::response(['id' => 'price_'.$gezaehlt]);
        }

        return null;
    });
    stripeAttrappe(['prices' => 503]);

    expect(fn () => app()->call([new PaketfassungAnlegen((string) $neu->uuid), 'handle']))->toThrow(RuntimeException::class);
    expect($neu->fresh()?->stripe_state)->toBe(PlanVersion::AUSSTEHEND)
        ->and($neu->fresh()?->activated_at)->toBeNull()
        ->and($neu->fresh()?->stripe_price_topup)->not->toBeNull()
        ->and($neu->fresh()?->stripe_price_image)->toBeNull();

    config()->set('test.stripe_scheitert', []);
    app()->call([new PaketfassungAnlegen((string) $neu->uuid), 'handle']);

    $angelegt = Http::recorded(fn (Request $anfrage, $antwort): bool => $anfrage->method() === 'POST'
        && str_ends_with($anfrage->url(), '/v1/prices')
        && $antwort->successful())
        ->map(fn (array $paar): Request => $paar[0]);

    expect($angelegt)->toHaveCount(4)
        ->and($angelegt->map(fn (Request $anfrage): string => $anfrage->header('Idempotency-Key')[0])->unique())->toHaveCount(4)
        ->and($angelegt->every(fn (Request $anfrage): bool => $anfrage->data()['product'] === 'prod_paket'))->toBeTrue()
        ->and($angelegt->filter(fn (Request $anfrage): bool => ($anfrage->data()['recurring[interval]'] ?? null) === 'month'))->toHaveCount(1)
        ->and(stripeAufrufe('POST', 'products/prod_paket'))->toHaveCount(1);

    $neu->refresh();

    expect($neu->stripe_state)->toBe(PlanVersion::BEREIT)
        ->and($neu->stripe_product_id)->toBe('prod_paket')
        ->and($neu->hatStripePreise())->toBeTrue()
        ->and(app(Paket::class)->aktuell()->is($neu))->toBeTrue();
});

it('kassiert unter der vorigen Fassung, bis alle vier Preise stehen', function (): void {
    mitStripe();
    paketMitStripe();
    Queue::fake();

    paketSpeichern()->assertSessionHasNoErrors();

    Http::fake([
        'stripe.test/v1/customers' => Http::response(['id' => 'cus_neu']),
        'stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/s/1']),
    ]);

    $praxis = alsMandant(organisation('Neu-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    actingAs($inhaberin)->post(route('abo.kasse'), ['was' => 'abo'])->assertRedirect('https://checkout.stripe.test/s/1');

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/checkout/sessions')
        && ($anfrage->data()['line_items[0][price]'] ?? null) === 'price_basis_1');
});

it('archiviert nach ready Grund- und Einrichtungspreis, und ein Abo darauf rechnet weiter', function (): void {
    mitStripe();
    $alt = paketMitStripe();
    $praxis = paketpraxis($alt);
    stripeAttrappe();

    paketSpeichern()->assertSessionHasNoErrors();

    expect(app(Paket::class)->aktuell()->base_cents)->toBe(89000)
        ->and(stripeAufrufe('POST', 'prices/price_basis_1')[0]->data())->toBe(['active' => 'false'])
        ->and(stripeAufrufe('POST', 'prices/price_einrichtung_1'))->toHaveCount(1)
        // Aufstockung und Bild kauft der Bestand weiter nach -- Stripes Kasse
        // nimmt keinen archivierten Preis an.
        ->and(stripeAufrufe('POST', 'prices/price_block_1'))->toBeEmpty()
        ->and(stripeAufrufe('POST', 'prices/price_bild_1'))->toBeEmpty()
        ->and(stripeAufrufe('POST', 'subscriptions/sub_demo-praxis'))->toBeEmpty()
        ->and(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe($alt->getKey());
});

it('haelt ein endgueltiges Scheitern bei Stripe fest und laesst die vorige Fassung gelten', function (): void {
    mitStripe();
    $alt = paketMitStripe();
    stripeAttrappe(['prices' => 400]);

    paketSpeichern()->assertSessionHasNoErrors();

    $neu = PlanVersion::query()->orderByDesc('number')->firstOrFail();

    expect($neu->stripe_state)->toBe(PlanVersion::GESCHEITERT)
        ->and($neu->stripe_error)->toContain('Abgelehnt von Stripe')
        ->and($neu->activated_at)->toBeNull()
        ->and(app(Paket::class)->aktuell()->is($alt))->toBeTrue()
        ->and(app(Betriebslage::class)->fuerInstallation()['gescheitertePaketfassungen'])->toBe(1)
        ->and(app(Betriebslage::class)->auffaellig())->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScopes()->whereNull('organization_id')->where('event', AuditEvent::PlanVersionFailed->value)->exists())->toBeTrue();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('backoffice.paket'))
        ->assertInertia(fn (Assert $seite) => $seite
            ->component('backoffice/Paket')
            ->where('aktuell.number', $alt->number)
            ->where('fassungen.0.stripeStand', PlanVersion::GESCHEITERT)
            ->where('fassungen.0.stripeFehler', fn (?string $fehler): bool => str_contains((string) $fehler, 'Abgelehnt'))
            ->where('inArbeit', false));

    // Wer es erneut versucht, legt eine neue Fassung an -- und die gilt.
    config()->set('test.stripe_scheitert', []);
    paketSpeichern(['grundpreis' => '899.00'])->assertSessionHasNoErrors();

    expect(app(Paket::class)->aktuell()->base_cents)->toBe(89900)
        ->and(app(Betriebslage::class)->fuerInstallation()['gescheitertePaketfassungen'])->toBe(0);
});

it('nimmt keine zweite Fassung an, solange eine bei Stripe angelegt wird', function (): void {
    mitStripe();
    paketMitStripe();
    Queue::fake();

    paketSpeichern()->assertSessionHasNoErrors();
    paketSpeichern(['grundpreis' => '899.00'])->assertSessionHasErrors('name');

    expect(PlanVersion::query()->where('stripe_state', PlanVersion::AUSSTEHEND)->count())->toBe(1);
});

/* Bestand ------------------------------------------------------------------ */

it('laesst bei "nur Neuabschluesse" den Bestand auf seiner Fassung und gibt einer neuen Praxis die neue', function (): void {
    $alt = neuesPaket(['included_messages' => 400, 'topup_cents' => 3900]);
    $bestand = paketpraxis($alt);

    paketSpeichern()->assertSessionHasNoErrors();

    $neu = app(Paket::class)->aktuell();

    expect(paketabo($bestand)->getAttributes()['plan_version_id'])->toBe($alt->getKey())
        ->and(app(TenantContext::class)->runAs($bestand, fn () => app(Kontingente::class)->enthalten()['nachrichten']))->toBe(400);

    $neuePraxis = organisation('Neu-Praxis');

    expect(app(TenantContext::class)->runAs($neuePraxis, fn () => app(Kontingente::class)->abo()->getAttributes()['plan_version_id']))->toBe($neu->getKey())
        ->and(app(TenantContext::class)->runAs($neuePraxis, fn () => app(Kontingente::class)->enthalten()['nachrichten']))->toBe(500);
});

it('nimmt Praxen in der Testphase immer mit -- sie haben noch nichts abgeschlossen', function (): void {
    $alt = app(Paket::class)->aktuell();
    $praxis = alsMandant(organisation('Test-Praxis'));
    app(Kontingente::class)->abo();
    ohneMandant();

    expect(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe($alt->getKey());

    paketSpeichern()->assertSessionHasNoErrors();

    expect(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe(app(Paket::class)->aktuell()->getKey())
        ->and(app(TenantContext::class)->runAs($praxis, fn () => app(Kontingente::class)->enthalten()['agentenlaeufe']))->toBe(700);
});

it('stellt bei "auch den Bestand" jedes Abo genau einmal ohne anteilige Verrechnung um', function (): void {
    mitStripe();
    $alt = paketMitStripe();
    $eins = paketpraxis($alt, 'Praxis Eins');
    $zwei = paketpraxis($alt, 'Praxis Zwei');
    stripeAttrappe();

    paketSpeichern(['bestand' => true])->assertSessionHasNoErrors();

    $neu = app(Paket::class)->aktuell();

    foreach (['sub_praxis-eins', 'sub_praxis-zwei'] as $abo) {
        $aufrufe = stripeAufrufe('POST', 'subscriptions/'.$abo);

        expect($aufrufe)->toHaveCount(1)
            ->and($aufrufe[0]->data())->toBe(['items[0][id]' => 'si_1', 'items[0][price]' => $neu->stripe_price_base, 'proration_behavior' => 'none'])
            ->and(str_starts_with($aufrufe[0]->header('Idempotency-Key')[0], (string) $neu->uuid))->toBeTrue();
    }

    // Ein zweiter Auftrag fuer dieselbe Praxis stellt nichts noch einmal um.
    app()->call([new AboAufFassungUmstellen((string) $eins->uuid, (string) $neu->uuid), 'handle']);

    expect(stripeAufrufe('POST', 'subscriptions/sub_praxis-eins'))->toHaveCount(1)
        ->and(paketabo($zwei)->getAttributes()['pending_plan_version_id'])->toBe($neu->getKey());
});

it('wechselt die Fassung erst mit dem Webhook und die Kontingente erst mit der naechsten Periode', function (): void {
    mitStripe();
    $alt = paketMitStripe();
    $praxis = paketpraxis($alt);
    stripeAttrappe();

    paketSpeichern(['bestand' => true])->assertSessionHasNoErrors();

    $neu = app(Paket::class)->aktuell();
    $enthalten = fn (): int => app(TenantContext::class)->runAs($praxis, fn () => app(Kontingente::class)->enthalten()['nachrichten']);

    // Der Auftrag hat umgestellt; die Fassung am Abo wartet.
    expect(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe($alt->getKey())
        ->and(paketabo($praxis)->getAttributes()['pending_plan_version_id'])->toBe($neu->getKey())
        ->and($enthalten())->toBe(400);

    // Stripe meldet den neuen Preis mitten im Monat: noch keine neue Periode.
    paketZustellung($praxis, (string) $neu->stripe_price_base, '2027-01-01 00:00:00', '2027-02-01 00:00:00', CarbonImmutable::now()->getTimestamp());

    expect(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe($alt->getKey())
        ->and($enthalten())->toBe(400);

    // Die naechste Periode beginnt.
    travelTo(CarbonImmutable::parse('2027-02-01 00:05:00', 'UTC'));
    paketZustellung($praxis, (string) $neu->stripe_price_base, '2027-02-01 00:00:00', '2027-03-01 00:00:00', CarbonImmutable::now()->getTimestamp());

    expect(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe($neu->getKey())
        ->and(paketabo($praxis)->getAttributes()['pending_plan_version_id'])->toBeNull()
        ->and($enthalten())->toBe(500)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('organization_id', $praxis->getKey())->where('event', AuditEvent::SubscriptionPlanChanged->value)->exists())->toBeTrue();
});

it('ordnet den ersten Abschluss sofort der Fassung seines Preises zu', function (): void {
    mitStripe();
    $alt = paketMitStripe();
    $praxis = alsMandant(organisation('Neu-Praxis'));
    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_neu-praxis';

    // Die Praxis hat noch unter Fassung 1 getestet und schliesst zur
    // aktuellen ab.
    $abo->plan_version_id = fassungNr(1)->getKey();
    $abo->save();
    ohneMandant();

    $daten = [
        'id' => 'evt_abschluss',
        'type' => 'customer.subscription.created',
        'created' => CarbonImmutable::now()->getTimestamp(),
        'data' => ['object' => [
            'id' => 'sub_neu-praxis',
            'customer' => 'cus_neu-praxis',
            'status' => 'active',
            'current_period_start' => CarbonImmutable::now()->getTimestamp(),
            'current_period_end' => CarbonImmutable::now()->addMonth()->getTimestamp(),
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_basis_1']]]],
        ]],
    ];

    postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();

    expect(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe($alt->getKey());
});

it('meldet einen Preis, den keine Fassung kennt, dem Betrieb -- und laesst das Abo, wo es war', function (): void {
    mitStripe();
    $alt = paketMitStripe();
    $praxis = paketpraxis($alt);

    paketZustellung($praxis, 'price_von_hand_angelegt', '2027-01-01 00:00:00', '2027-02-01 00:00:00', CarbonImmutable::now()->getTimestamp());

    expect(paketabo($praxis)->getAttributes()['plan_version_id'])->toBe($alt->getKey())
        ->and(paketabo($praxis)->getAttributes()['pending_plan_version_id'])->toBeNull()
        ->and(app(Betriebslage::class)->fuerInstallation()['paketHinweise'])->toBe(1);
});

it('zeigt auf der Abo-Seite die Preise der eigenen Fassung', function (): void {
    $alt = neuesPaket(['base_cents' => 79000, 'topup_cents' => 3900, 'image_price_cents' => 200, 'topup_messages' => 200]);
    $praxis = paketpraxis($alt);
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    paketSpeichern()->assertSessionHasNoErrors();

    actingAs($inhaberin)
        ->get(route('abo.edit'))
        ->assertInertia(fn (Assert $seite) => $seite
            ->component('settings/Abo')
            ->where('paket.grundpreisCent', 79000)
            ->where('blockpreisCent', 3900)
            ->where('bildpreisCent', 200)
            ->where('blockmengen.nachrichten', 200));
});

it('rechnet die Hochrechnung je Abo mit dem Grundpreis seiner Fassung', function (): void {
    $alt = neuesPaket(['base_cents' => 79000]);
    paketpraxis($alt, 'Alt-Praxis');

    paketSpeichern(['bestand' => false])->assertSessionHasNoErrors();

    paketpraxis(app(Paket::class)->aktuell(), 'Neu-Praxis');

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $seite) => $seite->where('kennzahlen.abos.mrrCent', 79000 + 89000));
});

/* Grenzen ------------------------------------------------------------------ */

it('laesst nur paket.verwalten auf die Seite', function (): void {
    actingAs(User::factory()->superAdmin()->create())->get(route('backoffice.paket'))->assertOk();
    actingAs(User::factory()->customerSuccess()->create())->get(route('backoffice.paket'))->assertForbidden();
    actingAs(User::factory()->finanzen()->create())->get(route('backoffice.paket'))->assertForbidden();

    $vorher = PlanVersion::query()->count();

    paketSpeichern(wer: User::factory()->customerSuccess()->create())->assertForbidden();
    paketSpeichern(wer: User::factory()->finanzen()->create())->assertForbidden();

    $praxis = organisation('Demo-Praxis');
    actingAs(User::factory()->fuer($praxis, Role::Owner)->create())->post(route('backoffice.paket.store'), paketFormular())->assertForbidden();

    expect(PlanVersion::query()->count())->toBe($vorher);
});

it('legt ohne richtiges Passwort und ohne Grund keine Fassung an', function (): void {
    $vorher = PlanVersion::query()->count();

    paketSpeichern(['current_password' => 'falsch'])->assertSessionHasErrors('current_password');
    paketSpeichern(['grund' => ''])->assertSessionHasErrors('grund');

    expect(PlanVersion::query()->count())->toBe($vorher);
});

it('zeigt vor dem Speichern, wie viele Abos eine Umstellung trifft', function (): void {
    $alt = app(Paket::class)->aktuell();
    paketpraxis($alt, 'Praxis Eins');
    paketpraxis($alt, 'Praxis Zwei');
    $test = alsMandant(organisation('Test-Praxis'));
    app(Kontingente::class)->abo();
    ohneMandant();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('backoffice.paket'))
        ->assertInertia(fn (Assert $seite) => $seite
            ->where('bestand.abgeschlossen', 2)
            ->where('bestand.testphase', 1)
            ->where('fassungen.0.abos', 3)
            ->where('stripeAngebunden', false));

    expect($test)->toBeInstanceOf(Organization::class);
});

it('nennt als Urheber einer Fassung ein geloeschtes Konto, nicht die Migration', function (): void {
    // Nachtrag 28.09.2026: Betreiberkonten lassen sich loeschen. Die Fassung
    // behaelt die Kennung ihres Urhebers -- nur findet sie keinen Namen mehr.
    $ehemalige = User::factory()->superAdmin()->create();
    paketSpeichern(wer: $ehemalige)->assertSessionHasNoErrors();

    $bleibende = User::factory()->superAdmin()->create();

    actingAs($bleibende)
        ->delete(route('backoffice.betreiber.loeschen', ['betreiber' => $ehemalige->uuid]), ['current_password' => 'password'])
        ->assertSessionHasNoErrors();

    actingAs($bleibende)
        ->get(route('backoffice.paket'))
        ->assertInertia(fn (Assert $seite) => $seite
            ->where('fassungen.0.von', 'Gelöschtes Konto')
            ->where('fassungen.1.von', null));
});

it('bietet in der Kasse keine Auswahl -- es gibt ein Paket', function (): void {
    mitStripe();
    $alt = paketMitStripe();
    $neu = neuesPaket(['stripe_price_base' => 'price_basis_2'], fuerAlle: false);

    Http::fake([
        'stripe.test/v1/customers' => Http::response(['id' => 'cus_neu']),
        'stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/s/1']),
    ]);

    $praxis = alsMandant(organisation('Neu-Praxis'));
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    // Wer eine Fassung mitschickt, bekommt trotzdem die aktuelle.
    actingAs($inhaberin)->post(route('abo.kasse'), ['was' => 'abo', 'fassung' => (string) $alt->uuid, 'paket' => 'premium']);

    Http::assertSent(fn (Request $anfrage): bool => str_ends_with($anfrage->url(), '/v1/checkout/sessions')
        && ($anfrage->data()['line_items[0][price]'] ?? null) === $neu->stripe_price_base
        && ! array_key_exists('line_items[2][price]', $anfrage->data()));
});

it('liest in app/ keinen Paketwert mehr aus der Konfiguration', function (): void {
    $verboten = [
        'mrs.billing.prices', 'mrs.billing.included', 'mrs.billing.topup', 'mrs.billing.image_price_cents', "'mrs.billing.trial_days'",
        'services.stripe.price_id', 'services.stripe.topup_price_id', 'services.stripe.setup_price_id', 'services.stripe.image_price_id',
    ];

    $treffer = collect(File::allFiles(app_path()))
        ->flatMap(function (SplFileInfo $datei) use ($verboten): array {
            $inhalt = (string) file_get_contents($datei->getPathname());

            return collect($verboten)
                ->filter(fn (string $schluessel): bool => str_contains($inhalt, $schluessel))
                ->map(fn (string $schluessel): string => str_replace(base_path().'/', '', $datei->getPathname()).': '.$schluessel)
                ->values()
                ->all();
        })
        ->all();

    expect($treffer)->toBe([]);
});

it('schreibt die Seite ohne Mandanten-Inhalte und mit einem Querzugriff', function (): void {
    paketpraxis(app(Paket::class)->aktuell());

    $vorher = AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::CrossTenantAccess->value)->count();

    actingAs(User::factory()->superAdmin()->create())->get(route('backoffice.paket'))->assertOk()->assertDontSee('Demo-Praxis');

    expect(AuditLog::query()->withoutGlobalScopes()->where('event', AuditEvent::CrossTenantAccess->value)->count())->toBe($vorher + 1);
});

it('erreicht die Seite nur angemeldet', function (): void {
    get(route('backoffice.paket'))->assertRedirect(route('backoffice.anmelden'));
});
