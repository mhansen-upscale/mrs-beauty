<?php

declare(strict_types=1);

use App\Abrechnung\Kontingente;
use App\Abrechnung\Nutzungsuebersicht;
use App\Abrechnung\Servicefensterabrechnung;
use App\Enums\ChannelType;
use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\WaitlistOfferStatus;
use App\Enums\WaitlistTrigger;
use App\Jobs\ServicefensterAbrechnen;
use App\Kanaele\Konversationen;
use App\Kanaele\Rueckmeldung;
use App\Kanaele\Rueckmeldungen;
use App\Models\ChannelIdentity;
use App\Models\Message;
use App\Models\User;
use App\Models\WaitlistOffer;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Warteliste\Wartelistenaufbau;

/*
|--------------------------------------------------------------------------
| Entscheidung B14 -- Antworten im Service-Fenster
|--------------------------------------------------------------------------
|
| Ob Meta ab dem 01.10.2026 auch Antworten im offenen Fenster berechnet,
| steht in den eigenen Unterlagen widerspruechlich. Deshalb: **gezaehlt wird
| ab jetzt, berechnet wird mit dem Preis aus der Umgebung** -- vorerst null
| Euro. Steigt der Preis, fliesst er ohne Codeaenderung in die naechste
| Rechnung.
|
| **Gesperrt wird eine Antwort nie** (B12), auch nicht bei einem Preis ueber
| null: sie zaehlt nicht gegen das Kontingent, sondern wird nachtraeglich
| abgerechnet.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));

    config()->set('services.stripe.key', 'sk_test_servicefenster');
    config()->set('services.stripe.url', 'https://stripe.test');
});

/** Eine ausgehende WhatsApp-Nachricht, wie sie nach dem Versand dasteht. */
function servicefensternachricht(
    ?MessageCostCategory $kategorie = MessageCostCategory::Service,
    ?int $preis = null,
    ?CarbonImmutable $wann = null,
    ?string $idempotenz = null,
): Message {
    $identitaet = ChannelIdentity::query()->firstOr(fn () => ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
    ]));

    $nachricht = new Message;
    $nachricht->conversation_id = app(Konversationen::class)->fuer($identitaet)->getKey();
    $nachricht->channel = ChannelType::WhatsApp;
    $nachricht->direction = MessageDirection::Outbound;
    $nachricht->status = MessageStatus::Sent;
    $nachricht->body = 'Gern, bis morgen.';
    $nachricht->external_id = 'wamid.'.bin2hex(random_bytes(6));
    $nachricht->idempotency_key = $idempotenz;
    $nachricht->cost_category = $kategorie;
    $nachricht->charge_tenth_cents = $preis;
    $nachricht->save();

    if ($wann instanceof CarbonImmutable) {
        $nachricht->forceFill(['created_at' => $wann])->save();
    }

    return $nachricht;
}

/** Ein Abo, das bei Stripe laeuft -- sonst gibt es keine Rechnung. */
function laufendesAbo(): void
{
    $abo = app(Kontingente::class)->abo();
    $abo->stripe_customer_id = 'cus_servicefenster';
    $abo->stripe_subscription_id = 'sub_servicefenster';
    $abo->save();
}

/* Zaehlen ------------------------------------------------------------------ */

it('zaehlt Antworten im Service-Fenster, aber nicht gegen das Kontingent', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    config()->set('mrs.billing.included.messages', 1);

    servicefensternachricht();
    servicefensternachricht();
    servicefensternachricht(MessageCostCategory::Utility);

    $nutzung = app(Nutzungsuebersicht::class)->fuerMonat();

    expect($nutzung['servicefenster'])->toBe(2)
        ->and($nutzung['kostenpflichtigeNachrichten'])->toBe(1)
        // Zwei Antworten verbrauchen nichts vom Kontingent -- das eine
        // Template aber schon.
        ->and(app(Kontingente::class)->rest()['nachrichten'])->toBe(0);
});

it('zaehlt eine Antwort ohne bekannte Kategorie noch nicht', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    servicefensternachricht(kategorie: null);

    expect(app(Nutzungsuebersicht::class)->fuerMonat()['servicefenster'])->toBe(0);
});

/* Preis beim Eintreffen der Kategorie --------------------------------------- */

it('haelt den Preis fest, sobald Meta die Kategorie meldet', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    config()->set('mrs.billing.service_window.price_tenth_cents', 0);
    $vorher = servicefensternachricht(kategorie: null);

    app(Rueckmeldungen::class)->trageNach(new Rueckmeldung(
        externeId: (string) $vorher->external_id,
        status: MessageStatus::Delivered,
        kategorie: MessageCostCategory::Service,
    ));

    // 1,5 Cent je Antwort, als Zehntel-Cent.
    config()->set('mrs.billing.service_window.price_tenth_cents', 15);
    $nachher = servicefensternachricht(kategorie: null);

    app(Rueckmeldungen::class)->trageNach(new Rueckmeldung(
        externeId: (string) $nachher->external_id,
        status: MessageStatus::Delivered,
        kategorie: MessageCostCategory::Service,
    ));

    // **Es gilt der Preis zum Zeitpunkt der Nachricht**, nicht der zum
    // Zeitpunkt der Rechnung. Wer den Preis erhoeht, erhoeht ihn ab dann.
    expect($vorher->refresh()->charge_tenth_cents)->toBe(0)
        ->and($nachher->refresh()->charge_tenth_cents)->toBe(15);
});

it('berechnet ein Template nicht einzeln -- es zaehlt gegen das Kontingent', function (): void {
    alsMandant(organisation('Demo-Praxis'));

    config()->set('mrs.billing.service_window.price_tenth_cents', 15);
    $template = servicefensternachricht(kategorie: null);

    app(Rueckmeldungen::class)->trageNach(new Rueckmeldung(
        externeId: (string) $template->external_id,
        status: MessageStatus::Delivered,
        kategorie: MessageCostCategory::Marketing,
    ));

    expect($template->refresh()->charge_tenth_cents)->toBeNull();
});

it('traegt die Kosten am Wartelistenangebot nach', function (): void {
    // Die offene Stelle aus WP-25: `cost_micros` stand, die Quelle fehlte.
    config()->set('mrs.billing.service_window.price_tenth_cents', 15);

    $angebot = wartelistenangebotFuerKosten(new Wartelistenaufbau);
    $nachricht = servicefensternachricht(kategorie: null, idempotenz: 'warteliste-'.$angebot->uuid);

    app(Rueckmeldungen::class)->trageNach(new Rueckmeldung(
        externeId: (string) $nachricht->external_id,
        status: MessageStatus::Delivered,
        kategorie: MessageCostCategory::Service,
    ));

    // 15 Zehntel-Cent sind 15.000 Mikro-Euro.
    expect($angebot->refresh()->cost_micros)->toBe(15_000);
});

/* Abrechnen ---------------------------------------------------------------- */

it('rechnet bei null Euro nichts bei Stripe ab', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    laufendesAbo();

    servicefensternachricht(preis: 0, wann: CarbonImmutable::parse('2026-12-10 10:00:00'));

    Http::fake();

    (new ServicefensterAbrechnen((string) $praxis->uuid, '2026-12'))->handle(
        app(TenantContext::class),
        app(Servicefensterabrechnung::class),
    );

    Http::assertNothingSent();

    expect(app(Kontingente::class)->abo()->service_window_billed_period)->toBe('2026-12');
});

it('rechnet einen Preis ueber null als einen Sammelposten ab', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    laufendesAbo();

    // Drei Antworten im Dezember zu je 1,5 Cent, eine im Januar.
    servicefensternachricht(preis: 15, wann: CarbonImmutable::parse('2026-12-03 10:00:00'));
    servicefensternachricht(preis: 15, wann: CarbonImmutable::parse('2026-12-14 10:00:00'));
    servicefensternachricht(preis: 15, wann: CarbonImmutable::parse('2026-12-31 22:00:00'));
    servicefensternachricht(preis: 15);

    Http::fake(['stripe.test/v1/invoiceitems' => Http::response(['id' => 'ii_1'])]);

    $lauf = fn () => (new ServicefensterAbrechnen((string) $praxis->uuid, '2026-12'))->handle(
        app(TenantContext::class),
        app(Servicefensterabrechnung::class),
    );

    $lauf();
    // Ein zweiter Lauf fuer denselben Monat schickt nichts mehr.
    $lauf();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $anfrage): bool => $anfrage->url() === 'https://stripe.test/v1/invoiceitems'
        && $anfrage['customer'] === 'cus_servicefenster'
        && $anfrage['subscription'] === 'sub_servicefenster'
        && $anfrage['currency'] === 'eur'
        // 3 x 1,5 Cent = 4,5 Cent, kaufmaennisch gerundet.
        && (int) $anfrage['amount'] === 5
        && str_contains((string) $anfrage['description'], '3 Antworten')
        && $anfrage->header('Idempotency-Key') === ['servicefenster-'.$praxis->uuid.'-2026-12']);
});

it('rechnet in der Testphase ohne Abo nichts ab', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));

    servicefensternachricht(preis: 15, wann: CarbonImmutable::parse('2026-12-10 10:00:00'));

    Http::fake();

    (new ServicefensterAbrechnen((string) $praxis->uuid, '2026-12'))->handle(
        app(TenantContext::class),
        app(Servicefensterabrechnung::class),
    );

    Http::assertNothingSent();
});

it('versucht es erneut, wenn Stripe nicht antwortet', function (): void {
    $praxis = alsMandant(organisation('Demo-Praxis'));
    laufendesAbo();

    servicefensternachricht(preis: 15, wann: CarbonImmutable::parse('2026-12-10 10:00:00'));

    Http::fake(['stripe.test/*' => Http::response([], 503)]);

    expect(fn () => (new ServicefensterAbrechnen((string) $praxis->uuid, '2026-12'))->handle(
        app(TenantContext::class),
        app(Servicefensterabrechnung::class),
    ))->toThrow(RuntimeException::class);

    // Nicht als abgerechnet vermerkt -- sonst ginge der Monat verloren.
    expect(app(Kontingente::class)->abo()->service_window_billed_period)->toBeNull();
});

it('reiht zum Monatsersten je Praxis einen Auftrag fuer den Vormonat ein', function (): void {
    Queue::fake();

    $eine = organisation('Praxis Nord');
    $andere = organisation('Praxis Sued');
    $gesperrt = organisation('Gesperrt');
    $gesperrt->forceFill(['suspended_at' => CarbonImmutable::now()])->save();

    expect(Artisan::call('mrs:servicefenster-abrechnen'))->toBe(0);

    Queue::assertPushed(ServicefensterAbrechnen::class, 2);
    Queue::assertPushed(ServicefensterAbrechnen::class, fn (ServicefensterAbrechnen $auftrag): bool => $auftrag->monat === '2026-12'
        && in_array($auftrag->organisation, [(string) $eine->uuid, (string) $andere->uuid], true));
});

/* Anzeige ------------------------------------------------------------------ */

it('zeigt die Antworten im Fenster samt Preis auf der Aboseite', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    config()->set('mrs.billing.service_window.price_tenth_cents', 0);
    servicefensternachricht(preis: 0);

    actingAs($inhaberin)
        ->get(route('abo.edit'))
        ->assertInertia(fn ($seite) => $seite
            ->component('settings/Abo')
            ->where('verbrauch.servicefenster', 1)
            ->where('servicefensterpreisZehntelCent', 0)
        );
});

/** Ein offenes Wartelistenangebot -- nur so viel, wie die Kosten brauchen. */
function wartelistenangebotFuerKosten(Wartelistenaufbau $aufbau): WaitlistOffer
{
    $eintrag = $aufbau->wartender('Ahrens');

    $angebot = new WaitlistOffer;
    $angebot->waitlist_entry_id = $eintrag->getKey();
    $angebot->entry_key = $eintrag->getKey();
    $angebot->practitioner_id = $aufbau->praxis->behandler->getKey();
    $angebot->location_id = $aufbau->praxis->standort->getKey();
    $angebot->appointment_type_id = $aufbau->praxis->art->getKey();
    $angebot->status = WaitlistOfferStatus::Pending;
    $angebot->trigger = WaitlistTrigger::Cancellation;
    $angebot->starts_at = CarbonImmutable::now()->addDay();
    $angebot->ends_at = CarbonImmutable::now()->addDay()->addMinutes(30);
    $angebot->blocked_from = CarbonImmutable::now()->addDay();
    $angebot->blocked_until = CarbonImmutable::now()->addDay()->addMinutes(30);
    $angebot->expires_at = CarbonImmutable::now()->addMinutes(30);
    $angebot->save();

    return $angebot;
}
