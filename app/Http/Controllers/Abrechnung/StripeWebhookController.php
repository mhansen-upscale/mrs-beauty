<?php

declare(strict_types=1);

namespace App\Http\Controllers\Abrechnung;

use App\Abrechnung\Kontingente;
use App\Abrechnung\Paket;
use App\Abrechnung\Stripe\Stripesignatur;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\TopUp;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Was Stripe uns ueber das Abo sagt.
 *
 * **Der Zustand kommt von dort, nicht aus einer Vermutung.** Wer im Portal
 * kuendigt, seine Karte wechselt oder nicht zahlt, tut das bei Stripe -- und
 * das Produkt erfaehrt es hier. Ein eigener Zustandsautomat daneben weicht
 * ab, sobald jemand das Portal benutzt.
 *
 * Derselbe Ablauf wie bei den Kanaelen (Entscheidung A14): Signatur pruefen,
 * sofort quittieren, dann arbeiten.
 */
final class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly Kontingente $kontingente,
        private readonly Paket $paket,
        private readonly AuditLogger $protokoll,
    ) {}

    public function __invoke(Request $request, TenantContext $mandant): Response
    {
        $rumpf = $request->getContent();

        if (! Stripesignatur::stimmt(
            $rumpf,
            $request->header('Stripe-Signature'),
            (string) config('services.stripe.webhook_secret'),
        )) {
            return response('', 403);
        }

        $art = (string) $request->input('type', '');
        $gegenstand = (array) $request->input('data.object', []);

        // **Der Zeitpunkt des Ereignisses, nicht der Zustellung** (WP-34c).
        // Stripe liefert in keiner festen Reihenfolge; wer `now()` nimmt,
        // haelt eine Pause fuer juenger als das Fortsetzen, das sie aufhob.
        $erstellt = $request->input('created');
        $zeitpunkt = is_numeric($erstellt) ? CarbonImmutable::createFromTimestamp((int) $erstellt, 'UTC') : CarbonImmutable::now();

        $kunde = data_get($gegenstand, 'customer');

        if (! is_string($kunde) || $kunde === '') {
            return response('', 200);
        }

        // **Die Zustellung kommt ohne Anmeldung an** und findet ihren
        // Mandanten ueber die Kundenkennung -- wie die Meta-Zustellung ueber
        // die Kennung der Gegenstelle (WP-19).
        $abo = $mandant->acrossTenants(
            'Stripe-Zustellung kommt ohne Anmeldung an und traegt nur die Kundenkennung',
            fn (): ?Subscription => Subscription::query()->where('stripe_customer_id', $kunde)->first(),
        );

        if (! $abo instanceof Subscription) {
            return response('', 200);
        }

        $organisation = Organization::query()->whereKey($abo->organization_id)->first();

        if (! $organisation instanceof Organization) {
            return response('', 200);
        }

        // **Jede Zustellung genau einmal** (WP-06 AK 15, WP-34c). Bis hier galt
        // das nur, weil die meisten Handler zufaellig idempotent waren -- die
        // Aufstockung war es nicht: zweimal zugestellt, zweimal gutgeschrieben.
        //
        // Vermerk und Verarbeitung in **einer** Transaktion: scheitert die
        // Verarbeitung, ist auch der Vermerk weg, und Stripes Wiederholung
        // kommt durch.
        $kennung = $request->input('id');

        DB::transaction(function () use ($kennung, $art, $mandant, $organisation, $gegenstand, $zeitpunkt): void {
            if (is_string($kennung) && $kennung !== '') {
                $neu = DB::table('stripe_events')->insertOrIgnore([
                    'id' => $kennung,
                    'type' => mb_substr($art, 0, 64),
                    'received_at' => CarbonImmutable::now(),
                ]);

                if ($neu === 0) {
                    return;
                }
            }

            $mandant->runAs($organisation, function () use ($art, $gegenstand, $zeitpunkt): void {
                match ($art) {
                    'checkout.session.completed',

                    // **SEPA zahlt spaeter** (28.09.2026). Die Kasse schliesst
                    // mit `unpaid`; das Geld meldet dieses Ereignis, Tage
                    // danach. Ohne es bekam, wer per Lastschrift aufstockte,
                    // nichts -- gutgeschrieben wird nur bei `paid`, also nie
                    // zweimal fuer dieselbe Kasse.
                    'checkout.session.async_payment_succeeded' => $this->nachKasse($gegenstand, $zeitpunkt),
                    'customer.subscription.created',
                    'customer.subscription.updated' => $this->zustand($gegenstand, $zeitpunkt),
                    'customer.subscription.deleted' => $this->beendet($zeitpunkt),
                    default => null,
                };
            });
        });

        return response('', 200);
    }

    /**
     * Eine Aufstockung ist bezahlt.
     *
     * **Erst jetzt gebucht** -- wer sie beim Oeffnen der Kasse gutschriebe,
     * verschenkte Kontingent an jeden, der sie wieder schliesst. Bei Karte
     * meldet es `checkout.session.completed`, bei SEPA erst
     * `checkout.session.async_payment_succeeded`.
     *
     * **Die Kasse ist der Schluessel** (WP-34d). Ein Ereignis zweimal faengt
     * `stripe_events` ab; dieselbe Kasse unter zwei Ereignissen nicht. Die
     * Zeile in `top_ups` haelt Zahlungszeitpunkt und Nettobetrag fuer die
     * Finanzuebersicht fest -- und wer sie schon hat, stockt nicht noch
     * einmal auf.
     *
     * @param  array<string, mixed>  $gegenstand
     */
    private function nachKasse(array $gegenstand, CarbonImmutable $zeitpunkt): void
    {
        if (data_get($gegenstand, 'mode') !== 'payment') {
            return;
        }

        if (data_get($gegenstand, 'payment_status') !== 'paid') {
            return;
        }

        $kasse = data_get($gegenstand, 'id');

        if (is_string($kasse) && $kasse !== '') {
            if (TopUp::query()->where('stripe_checkout_id', $kasse)->exists()) {
                return;
            }

            // Netto: Die Kasse rechnet die Steuer obendrauf (Stripe Tax,
            // `tax_behavior` exklusiv). Der Betrag kommt aus der Kasse, nicht
            // aus Menge mal Konfiguration -- gezahlt ist, was Stripe meldet.
            TopUp::query()->create([
                'article' => mb_substr((string) (data_get($gegenstand, 'metadata.artikel') ?? 'altbestand'), 0, 32),
                'quantity' => max(1, (int) (data_get($gegenstand, 'metadata.menge') ?? 1)),
                'amount_cents' => max(0, (int) data_get($gegenstand, 'amount_total', 0) - (int) data_get($gegenstand, 'total_details.amount_tax', 0)),
                'paid_at' => $zeitpunkt,
                'stripe_checkout_id' => $kasse,
            ]);
        }

        // **Was gekauft wurde, steht in den Metadaten.**
        //
        // `checkout.session.completed` liefert die Positionen nicht mit. Bis
        // WP-31 schrieb diese Stelle deshalb nach jeder Zahlung beides gut --
        // ungenau, aber folgenlos, solange beide Bloecke zusammen verkauft
        // wurden. Mit dem dritten Artikel waere daraus ein Fehler geworden:
        // wer Bilder kauft, bekaeme Nachrichten.
        $artikel = data_get($gegenstand, 'metadata.artikel');
        $menge = (int) (data_get($gegenstand, 'metadata.menge') ?? 1);

        match ($artikel) {
            'nachrichten' => $this->kontingente->stockeAuf('nachrichten', $this->blockfassung()->topup_messages),
            'agentenlaeufe' => $this->kontingente->stockeAuf('agentenlaeufe', $this->blockfassung()->topup_agent_runs),

            // Einzeln, nicht in Bloecken: bei zwei Euro das Stueck waere ein
            // Block von 250 eine Rechnung ueber 500 Euro.
            'bilder' => $this->kontingente->stockeAuf('bilder', max(1, $menge)),

            // Eine Zahlung ohne Angabe stammt aus der Zeit vor WP-31. Sie
            // bekommt, was sie damals bekommen haette.
            default => $this->altbestand(),
        };
    }

    /** Aufstockungen ohne Artikelangabe -- der Stand vor WP-31. */
    private function altbestand(): void
    {
        $this->kontingente->stockeAuf('nachrichten', $this->blockfassung()->topup_messages);
        $this->kontingente->stockeAuf('agentenlaeufe', $this->blockfassung()->topup_agent_runs);
    }

    /**
     * Wie gross ein Block ist, sagt die Fassung des Abos -- zu ihrem Preis
     * wurde er gekauft (WP-06b).
     */
    private function blockfassung(): PlanVersion
    {
        return $this->paket->fuer($this->kontingente->abo());
    }

    /**
     * Welche Fassung Stripe am Abo meldet -- ueber den Grundpreis der
     * Position (WP-06b).
     *
     * **Sofort** beim ersten Abschluss, sonst **zur naechsten Periode**
     * (`neuePeriode()`): eine Umstellung mitten im Monat aenderte die
     * Kontingente rueckwirkend. Ein unbekannter Preis ist ein Hinweis fuer den
     * Betrieb, keine Vermutung -- das Abo bleibt, wo es war.
     *
     * @param  array<string, mixed>  $gegenstand
     */
    private function ordneFassungZu(Subscription $abo, array $gegenstand, bool $ersterAbschluss): void
    {
        $preis = data_get($gegenstand, 'items.data.0.price.id');

        if (! is_string($preis) || $preis === '') {
            return;
        }

        $fassung = PlanVersion::query()->where('stripe_price_base', $preis)->first();

        if (! $fassung instanceof PlanVersion) {
            $this->protokoll->record(
                ereignis: AuditEvent::SubscriptionPriceUnknown,
                gegenstand: $abo,
                kontext: ['preis' => $preis],
            );

            return;
        }

        if (($abo->getAttributes()['plan_version_id'] ?? null) === $fassung->getKey()) {
            if ($abo->pending_plan_version_id !== null) {
                $abo->forceFill(['pending_plan_version_id' => null])->save();
            }

            return;
        }

        if ($ersterAbschluss) {
            $this->paket->wechsle($abo, $fassung);

            return;
        }

        $abo->forceFill(['pending_plan_version_id' => $fassung->getKey()])->save();
    }

    /**
     * Der Zustand des Abos, wie Stripe ihn meldet.
     *
     * **Aelteres aendert Neueres nicht** (WP-34c). Ein Ereignis von vor der
     * Pause, das nach ihr ankommt, hebt sie sonst still wieder auf.
     *
     * @param  array<string, mixed>  $gegenstand
     */
    private function zustand(array $gegenstand, CarbonImmutable $zeitpunkt): void
    {
        $abo = $this->kontingente->abo();

        if ($abo->stripe_event_at instanceof CarbonImmutable && $zeitpunkt->lessThan($abo->stripe_event_at)) {
            return;
        }

        $vorherigerBeginn = $abo->period_starts_at;
        $ersterAbschluss = ! is_string($abo->stripe_subscription_id) || $abo->stripe_subscription_id === '';

        $abo->status = SubscriptionStatus::ausStripe((string) data_get($gegenstand, 'status', ''));
        $abo->stripe_subscription_id = is_string(data_get($gegenstand, 'id'))
            ? (string) data_get($gegenstand, 'id')
            : $abo->stripe_subscription_id;

        // **Am Abo -- oder an seinen Positionen.** Ab der API-Version
        // 2025-03-31.basil stehen `current_period_*` nicht mehr am Abo. Der
        // Client nagelt die Version fest; hier stehen beide, damit ein
        // Wechsel am Webhook-Endpunkt nicht still jede neue Periode verliert.
        $beginn = $this->zeit($gegenstand, 'current_period_start') ?? $this->zeit($gegenstand, 'items.data.0.current_period_start');
        $ende = $this->zeit($gegenstand, 'current_period_end') ?? $this->zeit($gegenstand, 'items.data.0.current_period_end');

        $abo->period_starts_at = $beginn ?? $abo->period_starts_at;
        $abo->period_ends_at = $ende ?? $abo->period_ends_at;
        $abo->canceled_at = $abo->status === SubscriptionStatus::Canceled
            ? ($abo->canceled_at ?? $zeitpunkt)
            : null;

        // **Eine Pause laesst den Status auf `active`** (B17) -- die Sperre
        // haengt deshalb hier, nicht am Status.
        if (is_array(data_get($gegenstand, 'pause_collection'))) {
            $abo->paused_at ??= $zeitpunkt;
            $abo->pause_resumes_at = $this->zeit($gegenstand, 'pause_collection.resumes_at');
        } else {
            $abo->paused_at = null;
            $abo->pause_resumes_at = null;
        }

        $abo->cancel_at_period_end = (bool) data_get($gegenstand, 'cancel_at_period_end', false);
        $abo->cancel_at = $this->zeit($gegenstand, 'cancel_at');

        // Ein Gutschein fuer einmal traegt kein Enddatum: er gilt fuer die
        // naechste Rechnung, also die am Ende der laufenden Periode.
        $abo->discount_ends_at = is_array(data_get($gegenstand, 'discount'))
            ? ($this->zeit($gegenstand, 'discount.end') ?? $abo->period_ends_at)
            : null;

        // Der erste Wechsel auf aktiv -- die Einrichtung zaehlt genau einmal.
        if ($abo->status === SubscriptionStatus::Active && ! $abo->activated_at instanceof CarbonImmutable) {
            $abo->activated_at = $zeitpunkt;
        }

        $abo->stripe_event_at = $zeitpunkt;
        $abo->save();

        $this->ordneFassungZu($abo, $gegenstand, $ersterAbschluss);

        // **Eine neue Periode raeumt Aufgestocktes ab.** Wer im Januar
        // aufstockt, hat das im Februar verbraucht -- sonst waechst das
        // Kontingent still von Monat zu Monat.
        if ($beginn instanceof CarbonImmutable && $ende instanceof CarbonImmutable
            && (! $vorherigerBeginn instanceof CarbonImmutable || $beginn->greaterThan($vorherigerBeginn))) {
            $this->kontingente->neuePeriode($beginn, $ende);
        }
    }

    private function beendet(CarbonImmutable $zeitpunkt): void
    {
        $abo = $this->kontingente->abo();

        if ($abo->stripe_event_at instanceof CarbonImmutable && $zeitpunkt->lessThan($abo->stripe_event_at)) {
            return;
        }

        $abo->status = SubscriptionStatus::Canceled;
        $abo->canceled_at ??= $zeitpunkt;
        $abo->cancel_at_period_end = false;
        $abo->stripe_event_at = $zeitpunkt;
        $abo->save();
    }

    /**
     * @param  array<string, mixed>  $gegenstand
     */
    private function zeit(array $gegenstand, string $feld): ?CarbonImmutable
    {
        $wert = data_get($gegenstand, $feld);

        return is_numeric($wert) ? CarbonImmutable::createFromTimestamp((int) $wert, 'UTC') : null;
    }
}
