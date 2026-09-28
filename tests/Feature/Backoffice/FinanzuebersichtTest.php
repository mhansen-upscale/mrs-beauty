<?php

declare(strict_types=1);

use App\Abrechnung\Paket;
use App\Agent\Anfrage;
use App\Agent\Antwort;
use App\Agent\Sprachmodell;
use App\Agent\Verbrauch;
use App\Anzeigen\Bild;
use App\Anzeigen\Grafikablage;
use App\Anzeigen\Textentwurf;
use App\Backoffice\Finanzmonat;
use App\Backoffice\Finanzuebersicht;
use App\Enums\AgentAction;
use App\Enums\AuditEvent;
use App\Enums\Bildformat;
use App\Enums\BrandAddress;
use App\Enums\BrandTone;
use App\Enums\ChannelType;
use App\Enums\MessageCostCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\Vorschlagsstatus;
use App\Kanaele\Konversationen;
use App\Models\AdSuggestion;
use App\Models\AgentRun;
use App\Models\BrandGuide;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\MonthlyClosing;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TopUp;
use App\Models\User;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-34d -- Finanzuebersicht, Abnahmekriterien 1 bis 20
|--------------------------------------------------------------------------
|
| Eine Hochrechnung, keine Buchhaltung (B19). Einnahmen sind Preis mal
| Zustand, Kosten sind Menge mal Satz. Zahlen je Praxis, nie je Person.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));

    // Feste Saetze statt der Umgebung: der Test rechnet nach, nicht die
    // Tagesform einer .env.
    config()->set('mrs.backoffice.kosten', [
        'usd_eur' => 0.9,
        'whatsapp_zehntel_us_cent' => ['marketing' => 120, 'utility' => 40, 'authentication' => 50, 'service' => 10],
        'bild_format_zehntel_us_cent' => ['1x1' => 50, '4x5' => 80, '9x16' => 50],
        'stripe_prozent' => 1.5,
        'stripe_fix_cent' => 25,
        'fixkosten_cent_monat' => 100_000,
    ]);
});

/**
 * Eine Praxis mit einem Abo im gegebenen Zustand -- oder ohne Abo-Zeile.
 *
 * @param  array<string, mixed>  $felder
 */
function finanzpraxis(string $name, ?SubscriptionStatus $zustand = SubscriptionStatus::Active, array $felder = []): Organization
{
    $praxis = alsMandant(organisation($name));

    if ($zustand instanceof SubscriptionStatus) {
        $abo = new Subscription;
        $abo->status = $zustand;
        $abo->stripe_customer_id = 'cus_'.Str::lower(Str::random(8));
        $abo->stripe_subscription_id = $zustand === SubscriptionStatus::Trialing ? null : 'sub_'.Str::lower(Str::random(8));

        foreach ($felder as $feld => $wert) {
            $abo->setAttribute($feld, $wert);
        }

        $abo->save();
    }

    ohneMandant();

    return $praxis;
}

/** Eine ausgehende Nachricht der laufenden Praxis. */
function finanznachricht(?MessageCostCategory $kategorie, ?int $preisZehntelCent = null): Message
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
    $nachricht->charge_tenth_cents = $preisZehntelCent;
    $nachricht->save();

    return $nachricht;
}

/** Ein Assistenzlauf der laufenden Praxis, Kosten in Zehntel-US-Cent. */
function finanzlauf(int $kosten): AgentRun
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

/**
 * Ein Formatsatz der laufenden Praxis, so wie ihn das Bildmodell ablegt.
 *
 * @param  list<Bildformat>  $formate
 */
function finanzbildsatz(array $formate): void
{
    $vorschlag = new AdSuggestion;
    $vorschlag->week = CarbonImmutable::now()->startOfWeek();
    $vorschlag->headline = 'In Ruhe beraten lassen';
    $vorschlag->body = 'Wir nehmen uns Zeit für Ihre Fragen.';
    $vorschlag->status = Vorschlagsstatus::Entwurf;
    $vorschlag->save();

    $satz = Uuid::generate();

    foreach ($formate as $format) {
        app(Grafikablage::class)->lege($vorschlag, $format, new Bild('bilddaten-'.$format->value, 'image/png', 'testmodell'), $satz, null);
    }
}

/** Eine bezahlte Aufstockung der laufenden Praxis. */
function finanzaufstockung(string $artikel, int $betrag, CarbonImmutable $bezahlt): TopUp
{
    return TopUp::query()->create([
        'article' => $artikel,
        'quantity' => 1,
        'amount_cents' => $betrag,
        'paid_at' => $bezahlt,
        'stripe_checkout_id' => 'cs_'.Str::lower(Str::random(12)),
    ]);
}

function finanzmonat(?CarbonImmutable $monat = null): Finanzmonat
{
    return app(Finanzuebersicht::class)->monat($monat ?? CarbonImmutable::now());
}

/* Einnahmen ----------------------------------------------------------------- */

it('rechnet einer aktiven Praxis den Grundpreis ihrer Fassung', function (): void {
    $alt = app(Paket::class)->aktuell();
    $bestand = finanzpraxis('Bestand', SubscriptionStatus::Active, ['plan_version_id' => $alt->getKey()]);

    // Eine neue Fassung ohne Umstellung des Bestands: wer auf Fassung 1
    // abgeschlossen hat, zahlt den Preis von Fassung 1 (B20).
    neuesPaket(['base_cents' => $alt->base_cents + 10_000], fuerAlle: false);
    $neu = finanzpraxis('Neu');

    $monat = finanzmonat();

    expect($monat->praxis($bestand->uuid)?->grundpreis)->toBe($alt->base_cents)
        ->and($monat->praxis($neu->uuid)?->grundpreis)->toBe($alt->base_cents + 10_000);
});

it('rechnet pausiert, Gratismonat, Testphase und gekuendigt keinen Grundpreis', function (): void {
    $pausiert = finanzpraxis('Pausiert', SubscriptionStatus::Active, ['paused_at' => now()->subDays(3)]);
    $gratis = finanzpraxis('Gratis', SubscriptionStatus::Active, ['discount_ends_at' => now()->addDays(10)]);
    $test = finanzpraxis('Test', SubscriptionStatus::Trialing);
    $ohneZeile = finanzpraxis('Ohne Zeile', null);
    $gekuendigt = finanzpraxis('Gekuendigt', SubscriptionStatus::Canceled, ['canceled_at' => now()->subDays(40)]);

    $monat = finanzmonat();

    foreach ([$pausiert, $gratis, $test, $ohneZeile, $gekuendigt] as $praxis) {
        expect($monat->praxis($praxis->uuid)?->grundpreis)->toBe(0, $praxis->name);
    }
});

it('zaehlt eine Praxis mit offener Zahlung mit', function (): void {
    $praxis = finanzpraxis('Offen', SubscriptionStatus::PastDue);

    expect(finanzmonat()->praxis($praxis->uuid)?->grundpreis)->toBe(app(Paket::class)->aktuell()->base_cents);
});

it('zaehlt die Einrichtung genau einmal, im Monat der Aktivierung', function (): void {
    $praxis = finanzpraxis('Neu', SubscriptionStatus::Active, ['activated_at' => CarbonImmutable::parse('2027-01-05 10:00:00')]);
    $einrichtung = app(Paket::class)->aktuell()->setup_cents;

    expect(finanzmonat()->praxis($praxis->uuid)?->einrichtung)->toBe($einrichtung);

    // Pause und Fortsetzung aendern activated_at nicht.
    alsMandant($praxis);
    $abo = Subscription::query()->sole();
    $abo->paused_at = now();
    $abo->save();
    $abo->paused_at = null;
    $abo->save();
    ohneMandant();

    travelTo(CarbonImmutable::parse('2027-02-12 08:00:00', 'UTC'));

    expect(finanzmonat()->praxis($praxis->uuid)?->einrichtung)->toBe(0)
        ->and(finanzmonat(CarbonImmutable::parse('2027-01-15'))->praxis($praxis->uuid)?->einrichtung)->toBe($einrichtung);
});

it('zaehlt eine Aufstockung im Monat ihrer Zahlung mit dem gezahlten Betrag', function (): void {
    $praxis = finanzpraxis('Aufstockung');

    alsMandant($praxis);
    finanzaufstockung('nachrichten', 5_900, CarbonImmutable::parse('2027-01-03 12:00:00'));
    finanzaufstockung('bilder', 1_000, CarbonImmutable::parse('2027-01-04 12:00:00'));
    finanzaufstockung('nachrichten', 5_900, CarbonImmutable::parse('2026-12-30 12:00:00'));
    ohneMandant();

    $zeile = finanzmonat()->praxis($praxis->uuid);

    expect($zeile?->aufstockungen)->toBe(5_900)
        ->and($zeile?->bilder)->toBe(1_000);
});

it('legt je Kasse eine Aufstockung an, netto, auch wenn Stripe sie zweimal meldet', function (): void {
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    $praxis = alsMandant(organisation('Demo-Praxis'));
    $abo = new Subscription;
    $abo->status = SubscriptionStatus::Active;
    $abo->stripe_customer_id = 'cus_1';
    $abo->save();
    ohneMandant();

    $kasse = [
        'id' => 'cs_test_1',
        'customer' => 'cus_1',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'amount_total' => 7_021,
        'total_details' => ['amount_tax' => 1_121],
        'metadata' => ['artikel' => 'nachrichten', 'menge' => 1],
    ];

    // Zwei Ereignisse, dieselbe Kasse: die Kontrolle ueber stripe_events
    // greift nicht, die Kasse selbst ist der Schluessel.
    foreach (['evt_1' => 'checkout.session.completed', 'evt_2' => 'checkout.session.async_payment_succeeded'] as $kennung => $art) {
        $daten = ['id' => $kennung, 'type' => $art, 'created' => CarbonImmutable::parse('2027-01-10 09:00:00')->getTimestamp(), 'data' => ['object' => $kasse]];
        postJson(route('stripe.webhook'), $daten, stripekopf($daten))->assertOk();
    }

    alsMandant($praxis);

    $zeile = TopUp::query()->sole();

    expect($zeile->amount_cents)->toBe(5_900)
        ->and($zeile->article)->toBe('nachrichten')
        ->and($zeile->paid_at->toDateTimeString())->toBe('2027-01-10 09:00:00')
        ->and(Subscription::query()->sole()->extra_messages)->toBe(app(Paket::class)->aktuell()->topup_messages);
});

it('zaehlt das Service-Fenster nur bei laufendem Stripe-Abo', function (): void {
    $mitAbo = finanzpraxis('Mit Abo');
    $ohneAbo = finanzpraxis('Ohne Abo', SubscriptionStatus::Active, ['stripe_subscription_id' => null]);

    foreach ([$mitAbo, $ohneAbo] as $praxis) {
        alsMandant($praxis);
        finanznachricht(MessageCostCategory::Service, 50);
        finanznachricht(MessageCostCategory::Service, 50);
        ohneMandant();
    }

    $monat = finanzmonat();

    expect($monat->praxis($mitAbo->uuid)?->servicefenster)->toBe(10)
        ->and($monat->praxis($ohneAbo->uuid)?->servicefenster)->toBe(0);
});

/* Kosten -------------------------------------------------------------------- */

it('rechnet die Modellkosten des Agenten aus Zehntel-US-Cent in Euro um', function (): void {
    $praxis = finanzpraxis('Agent');

    alsMandant($praxis);
    finanzlauf(1_000);
    finanzlauf(500);
    ohneMandant();

    // 1.500 Zehntel-US-Cent sind 1,50 USD, bei 0,9 also 135 Euro-Cent.
    expect(finanzmonat()->praxis($praxis->uuid)?->sprachmodellAgent)->toBe(135);
});

it('laesst einen Textentwurf einmal kosten, auch ohne lesbare Antwort', function (string $inhalt): void {
    $praxis = alsMandant(organisation('Anzeigen'));
    BrandGuide::query()->create([
        'tone' => BrandTone::Warm->value,
        'address_form' => BrandAddress::Sie->value,
        'audience' => 'Frauen ab 35',
        'positioning' => 'Beratung ohne Verkaufsdruck',
    ]);

    app()->bind(Sprachmodell::class, fn (): Sprachmodell => new class($inhalt) implements Sprachmodell
    {
        public function __construct(private readonly string $inhalt) {}

        public function frage(Anfrage $anfrage): Antwort
        {
            return new Antwort($this->inhalt, 'claude-sonnet-5-20260101', 2_000, 800);
        }

        public function angebunden(): bool
        {
            return true;
        }
    });

    app(Textentwurf::class)->entwuerfe();

    $erwartet = new Verbrauch;
    $erwartet->eingabe = 2_000;
    $erwartet->ausgabe = 800;

    // **Eine Zeile je Aufruf, nicht je Vorschlag** -- und bepreist mit dem
    // Modell der Konfiguration, nicht mit dem Namen, den die API meldet.
    $zeile = ModelCall::query()->sole();

    expect($zeile->input_tokens)->toBe(2_000)
        ->and($zeile->output_tokens)->toBe(800)
        ->and($zeile->cost_tenth_cents)->toBe($erwartet->kostenZehntelCent((string) config('mrs.agent.model')))
        ->and($zeile->cost_tenth_cents)->toBeGreaterThan(0);

    ohneMandant();

    expect(finanzmonat()->praxis($praxis->uuid)?->sprachmodellAnzeigen)->toBeGreaterThan(0);
})->with([
    'drei Vorschlaege' => fn (): string => (string) json_encode(['varianten' => [
        ['ueberschrift' => 'Eins', 'text' => 'Text eins.'],
        ['ueberschrift' => 'Zwei', 'text' => 'Text zwei.'],
        ['ueberschrift' => 'Drei', 'text' => 'Text drei.'],
    ]]),
    'unlesbar' => fn (): string => 'Hier ist leider kein JSON.',
]);

it('rechnet einen Bildsatz mit drei Formaten dreimal zum Satz des Formats', function (): void {
    $praxis = finanzpraxis('Bilder');

    alsMandant($praxis);
    finanzbildsatz(Bildformat::cases());
    ohneMandant();

    // 50 + 80 + 50 Zehntel-US-Cent = 18 US-Cent, bei 0,9 also 16 Euro-Cent.
    expect(finanzmonat()->praxis($praxis->uuid)?->bildkosten)->toBe(16);
});

it('rechnet WhatsApp je Kategorie, ohne Kategorie none', function (): void {
    $praxis = finanzpraxis('WhatsApp');

    alsMandant($praxis);
    finanznachricht(MessageCostCategory::Marketing);
    finanznachricht(MessageCostCategory::Marketing);
    finanznachricht(MessageCostCategory::Utility);
    finanznachricht(MessageCostCategory::None);
    finanznachricht(MessageCostCategory::None);
    finanznachricht(null);
    ohneMandant();

    // 2 × 120 + 40 = 280 Zehntel-US-Cent = 28 US-Cent, bei 0,9 also 25.
    expect(finanzmonat()->praxis($praxis->uuid)?->whatsapp)->toBe(25);
});

it('zaehlt nichts doppelt und den Preis an die Praxis nicht als Kosten', function (): void {
    $praxis = finanzpraxis('Doppelt');

    alsMandant($praxis);
    // Die Praxis zahlt 50 Zehntel-Cent je Antwort, Meta berechnet uns 10
    // Zehntel-US-Cent. Kosten sind die 10, nicht die 50.
    finanznachricht(MessageCostCategory::Service, 50);
    ohneMandant();

    $zeile = finanzmonat()->praxis($praxis->uuid);

    expect($zeile?->whatsapp)->toBe(1)
        ->and($zeile?->servicefenster)->toBe(5);

    // Die Wartelistenangebote gehen nicht zusaetzlich ein: ihr Betrag stammt
    // aus charge_tenth_cents derselben Nachricht (waitlist_offers.cost_micros).
    $quelle = (string) file_get_contents(app_path('Backoffice/Finanzuebersicht.php'));

    expect($quelle)->not->toContain('WaitlistOffer')
        ->and($quelle)->not->toContain('cost_micros');
});

it('rechnet Stripe je Rechnung und nur fuer Praxen mit Stripe', function (): void {
    $mitStripe = finanzpraxis('Mit Stripe');
    $ohne = finanzpraxis('Testbetrieb', SubscriptionStatus::Active, ['stripe_customer_id' => null, 'stripe_subscription_id' => null]);

    alsMandant($mitStripe);
    finanzaufstockung('nachrichten', 5_900, CarbonImmutable::now());
    ohneMandant();

    $monat = finanzmonat();
    $grundpreis = app(Paket::class)->aktuell()->base_cents;

    // Zwei Rechnungen: das Abo und die Aufstockung.
    expect($monat->praxis($mitStripe->uuid)?->zahlungsverkehr)->toBe((int) round(($grundpreis + 5_900) * 0.015) + 2 * 25)
        ->and($monat->praxis($ohne->uuid)?->zahlungsverkehr)->toBe(0);
});

it('nennt einen fehlenden Satz, statt mit null zu rechnen', function (): void {
    config()->set('mrs.backoffice.kosten.usd_eur', null);
    config()->set('mrs.backoffice.kosten.whatsapp_zehntel_us_cent.utility', null);

    $praxis = finanzpraxis('Unvollstaendig');

    alsMandant($praxis);
    finanzlauf(1_000);
    finanznachricht(MessageCostCategory::Utility);
    ohneMandant();

    $zeile = finanzmonat()->praxis($praxis->uuid);

    expect($zeile?->fehlendeSaetze)->toContain('usd_eur', 'whatsapp.utility')
        ->and($zeile?->unvollstaendig())->toBeTrue();
});

/* Summen und Historie ------------------------------------------------------- */

it('ergibt als Summe der Praxiszeilen die Gesamtzahl, in jeder Spalte', function (): void {
    foreach (['Nord', 'Sued', 'West'] as $i => $name) {
        $praxis = finanzpraxis($name, $i === 2 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active);

        alsMandant($praxis);
        finanzlauf(100 * ($i + 1));
        finanznachricht(MessageCostCategory::Marketing);
        finanzaufstockung('bilder', 200 * ($i + 1), CarbonImmutable::now());
        ohneMandant();
    }

    $monat = finanzmonat();
    $summe = $monat->summe()->toArray();

    foreach (['grundpreis', 'einrichtung', 'aufstockungen', 'bilder', 'servicefenster'] as $posten) {
        expect($summe['einnahmen'][$posten])->toBe(array_sum(array_map(fn ($zeile): int => $zeile->toArray()['einnahmen'][$posten], $monat->praxen)), $posten);
    }

    foreach (['sprachmodellAgent', 'sprachmodellAnzeigen', 'whatsapp', 'bildkosten', 'zahlungsverkehr'] as $posten) {
        expect($summe['kosten'][$posten])->toBe(array_sum(array_map(fn ($zeile): int => $zeile->toArray()['kosten'][$posten], $monat->praxen)), $posten);
    }

    expect($summe['einnahmenCent'])->toBe(array_sum(array_map(fn ($zeile): int => $zeile->einnahmen(), $monat->praxen)))
        ->and($summe['kostenCent'])->toBe(array_sum(array_map(fn ($zeile): int => $zeile->kosten(), $monat->praxen)));
});

it('schliesst den Vormonat idempotent ab, auch fuer gesperrte Praxen', function (): void {
    $praxis = finanzpraxis('Aktiv');
    $gesperrt = finanzpraxis('Gesperrt');
    $gesperrt->suspended_at = now();
    $gesperrt->save();

    alsMandant($praxis);
    finanzaufstockung('nachrichten', 5_900, CarbonImmutable::parse('2026-12-20 10:00:00'));
    ohneMandant();

    expect(Artisan::call('mrs:monatsabschluss'))->toBe(0);

    alsMandant($praxis);
    $abschluss = MonthlyClosing::query()->sole();
    $stand = $abschluss->toArray();

    expect($abschluss->month)->toBe('2026-12')
        ->and($abschluss->aufstockungen_cents)->toBe(5_900)
        ->and($abschluss->grundpreis_cents)->toBe(app(Paket::class)->aktuell()->base_cents);

    // Was danach dazukommt, aendert den abgeschlossenen Monat nicht.
    finanzaufstockung('nachrichten', 5_900, CarbonImmutable::parse('2026-12-21 10:00:00'));
    ohneMandant();

    expect(Artisan::call('mrs:monatsabschluss', ['--monat' => '2026-12']))->toBe(0);

    alsMandant($praxis);
    expect(MonthlyClosing::query()->sole()->toArray())->toBe($stand);

    alsMandant($gesperrt);
    expect(MonthlyClosing::query()->where('month', '2026-12')->count())->toBe(1);
});

it('schliesst keinen laufenden Monat ab', function (): void {
    finanzpraxis('Aktiv');

    expect(Artisan::call('mrs:monatsabschluss', ['--monat' => '2027-01']))->toBe(1)
        ->and(MonthlyClosing::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('zeigt Monate vor dem ersten Abschluss als keine Daten, nicht als null', function (): void {
    finanzpraxis('Aktiv');
    Artisan::call('mrs:monatsabschluss');

    $seite = actingAs(User::factory()->finanzen()->create())
        ->get(route('backoffice.finanzen'))
        ->assertOk();

    /** @var list<array{monat: string, daten: bool, einnahmenCent: int|null}> $verlauf */
    $verlauf = $seite->inertiaProps('verlauf');

    /** @var array<string, array{monat: string, daten: bool, einnahmenCent: int|null}> $nachMonat */
    $nachMonat = array_column($verlauf, null, 'monat');

    expect($verlauf)->toHaveCount((int) config('mrs.backoffice.finanzen_monate'))
        ->and($nachMonat['2027-01']['daten'])->toBeTrue()
        ->and($nachMonat['2026-12']['daten'])->toBeTrue()
        ->and($nachMonat['2026-12']['einnahmenCent'])->toBeGreaterThan(0)
        ->and($nachMonat['2026-11']['daten'])->toBeFalse()
        ->and($nachMonat['2026-11']['einnahmenCent'])->toBeNull();
});

it('zeigt ohne hinterlegte Fixkosten nicht hinterlegt statt eines Ergebnisses', function (): void {
    config()->set('mrs.backoffice.kosten.fixkosten_cent_monat', null);
    finanzpraxis('Aktiv');

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('backoffice.finanzen'))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite
            ->component('backoffice/Finanzen')
            ->where('kennzahlen.fixkostenCent', null)
            ->where('kennzahlen.ergebnisCent', null)
            ->where('kennzahlen.rohertragCent', fn (int $wert): bool => $wert > 0)
        );
});

it('rechnet die Kennzahlen des laufenden Monats', function (): void {
    finanzpraxis('Zahlend');
    finanzpraxis('Offen', SubscriptionStatus::PastDue);
    finanzpraxis('Test', SubscriptionStatus::Trialing);
    finanzpraxis('Pause', SubscriptionStatus::Active, ['paused_at' => now()]);
    finanzpraxis('Geht', SubscriptionStatus::Active, ['cancel_at_period_end' => true, 'cancel_at' => now()->addDays(10)]);
    finanzpraxis('Weg', SubscriptionStatus::Canceled, ['canceled_at' => now()->subDays(2)]);

    $grundpreis = app(Paket::class)->aktuell()->base_cents;

    actingAs(User::factory()->finanzen()->create())
        ->get(route('backoffice.finanzen'))
        ->assertInertia(fn ($seite) => $seite
            ->where('kennzahlen.zahlend', 3)
            ->where('kennzahlen.testphase', 1)
            ->where('kennzahlen.pausiert', 1)
            ->where('kennzahlen.gekuendigtZumPeriodenende', 1)
            ->where('kennzahlen.kuendigungenImMonat', 1)
            ->where('kennzahlen.mrrCent', 3 * $grundpreis)
            ->where('kennzahlen.arrCent', 36 * $grundpreis)
            ->has('praxen', 6)
        );
});

/* Grenzen ------------------------------------------------------------------- */

it('laesst Customer Success weder auf die Seite noch an den Kasten im Mandantenblatt', function (): void {
    $praxis = finanzpraxis('Aktiv');

    actingAs(User::factory()->customerSuccess()->create())->get(route('backoffice.finanzen'))->assertForbidden();

    actingAs(User::factory()->customerSuccess()->create())
        ->get(route('backoffice.show', ['organisation' => $praxis->uuid]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->missing('wirtschaftlichkeit'));

    foreach ([User::factory()->finanzen()->create(), User::factory()->superAdmin()->create()] as $betreiber) {
        actingAs($betreiber)->get(route('backoffice.finanzen'))->assertOk();

        actingAs($betreiber)
            ->get(route('backoffice.show', ['organisation' => $praxis->uuid]))
            ->assertInertia(fn ($seite) => $seite
                ->has('wirtschaftlichkeit', (int) config('mrs.backoffice.wirtschaftlichkeit_monate'))
                ->where('wirtschaftlichkeit.0.daten', true)
            );
    }
});

it('schreibt je Seitenaufruf genau einen Querzugriff', function (): void {
    finanzpraxis('Nord');
    finanzpraxis('Sued');
    $betreiber = User::factory()->finanzen()->create();

    $vorher = DB::table('audit_logs')->where('event', AuditEvent::CrossTenantAccess->value)->count();

    actingAs($betreiber)->get(route('backoffice.finanzen'))->assertOk();

    expect(DB::table('audit_logs')->where('event', AuditEvent::CrossTenantAccess->value)->count())->toBe($vorher + 1);
});

it('nennt keinen Kontakt, keine E-Mail-Adresse und keine Telefonnummer', function (): void {
    $praxis = finanzpraxis('Demo-Praxis');

    alsMandant($praxis);
    Contact::create(['first_name' => 'Annika', 'last_name' => 'Rosenkohl', 'email' => 'annika@example.test']);
    finanznachricht(MessageCostCategory::Marketing);
    ohneMandant();

    $inhalt = (string) actingAs(User::factory()->superAdmin()->create())
        ->get(route('backoffice.finanzen'))
        ->assertOk()
        ->getContent();

    expect($inhalt)->not->toContain('Rosenkohl')
        ->and($inhalt)->not->toContain('annika@example.test')
        ->and($inhalt)->not->toContain('4915112345678');
});

it('braucht nicht mehr Abfragen, wenn es mehr Praxen gibt', function (): void {
    $zaehle = function (): int {
        $betreiber = User::factory()->finanzen()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        actingAs($betreiber)->get(route('backoffice.finanzen'))->assertOk();
        $anzahl = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $anzahl;
    };

    $eine = finanzpraxis('Eins');
    alsMandant($eine);
    finanzlauf(100);
    finanznachricht(MessageCostCategory::Marketing);
    ohneMandant();

    $bei1 = $zaehle();

    foreach (['Zwei', 'Drei', 'Vier'] as $i => $name) {
        $praxis = finanzpraxis($name, $i === 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active);
        alsMandant($praxis);
        finanzlauf(100);
        finanznachricht(MessageCostCategory::Utility);
        finanzaufstockung('bilder', 200, CarbonImmutable::now());
        ohneMandant();
    }

    expect($zaehle())->toBe($bei1);
});

it('rechnet der Installation den Gratismonat nicht als Umsatz', function (): void {
    finanzpraxis('Zahlend');
    finanzpraxis('Gratis', SubscriptionStatus::Active, ['discount_ends_at' => now()->addDays(10)]);

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('kennzahlen.abos.mrrCent', app(Paket::class)->aktuell()->base_cents));
});

it('zeigt der Praxis nichts davon', function (): void {
    $praxis = finanzpraxis('Aktiv');
    $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();

    actingAs($inhaberin)->get(route('backoffice.finanzen'))->assertForbidden();
});
