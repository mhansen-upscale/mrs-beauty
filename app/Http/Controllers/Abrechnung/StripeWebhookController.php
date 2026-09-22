<?php

declare(strict_types=1);

namespace App\Http\Controllers\Abrechnung;

use App\Abrechnung\Kontingente;
use App\Abrechnung\Stripe\Stripesignatur;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    public function __construct(private readonly Kontingente $kontingente) {}

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

        $mandant->runAs($organisation, function () use ($art, $gegenstand): void {
            match ($art) {
                'checkout.session.completed' => $this->nachKasse($gegenstand),
                'customer.subscription.created',
                'customer.subscription.updated' => $this->zustand($gegenstand),
                'customer.subscription.deleted' => $this->beendet(),
                default => null,
            };
        });

        return response('', 200);
    }

    /**
     * Eine Aufstockung ist bezahlt.
     *
     * **Erst jetzt gebucht** -- wer sie beim Oeffnen der Kasse gutschriebe,
     * verschenkte Kontingent an jeden, der sie wieder schliesst.
     *
     * @param  array<string, mixed>  $gegenstand
     */
    private function nachKasse(array $gegenstand): void
    {
        if (data_get($gegenstand, 'mode') !== 'payment') {
            return;
        }

        if (data_get($gegenstand, 'payment_status') !== 'paid') {
            return;
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
            'nachrichten' => $this->kontingente->stockeAuf('nachrichten', (int) config('mrs.billing.topup.messages')),
            'agentenlaeufe' => $this->kontingente->stockeAuf('agentenlaeufe', (int) config('mrs.billing.topup.agent_runs')),

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
        $this->kontingente->stockeAuf('nachrichten', (int) config('mrs.billing.topup.messages'));
        $this->kontingente->stockeAuf('agentenlaeufe', (int) config('mrs.billing.topup.agent_runs'));
    }

    /**
     * @param  array<string, mixed>  $gegenstand
     */
    private function zustand(array $gegenstand): void
    {
        $abo = $this->kontingente->abo();

        $vorherigerBeginn = $abo->period_starts_at;

        $abo->status = SubscriptionStatus::ausStripe((string) data_get($gegenstand, 'status', ''));
        $abo->stripe_subscription_id = is_string(data_get($gegenstand, 'id'))
            ? (string) data_get($gegenstand, 'id')
            : $abo->stripe_subscription_id;

        $beginn = $this->zeit($gegenstand, 'current_period_start');
        $ende = $this->zeit($gegenstand, 'current_period_end');

        $abo->period_starts_at = $beginn ?? $abo->period_starts_at;
        $abo->period_ends_at = $ende ?? $abo->period_ends_at;
        $abo->canceled_at = $abo->status === SubscriptionStatus::Canceled
            ? ($abo->canceled_at ?? CarbonImmutable::now())
            : null;

        $abo->save();

        // **Eine neue Periode raeumt Aufgestocktes ab.** Wer im Januar
        // aufstockt, hat das im Februar verbraucht -- sonst waechst das
        // Kontingent still von Monat zu Monat.
        if ($beginn instanceof CarbonImmutable && $ende instanceof CarbonImmutable
            && (! $vorherigerBeginn instanceof CarbonImmutable || $beginn->greaterThan($vorherigerBeginn))) {
            $this->kontingente->neuePeriode($beginn, $ende);
        }
    }

    private function beendet(): void
    {
        $abo = $this->kontingente->abo();

        $abo->status = SubscriptionStatus::Canceled;
        $abo->canceled_at ??= CarbonImmutable::now();
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
