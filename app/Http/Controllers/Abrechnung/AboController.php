<?php

declare(strict_types=1);

namespace App\Http\Controllers\Abrechnung;

use App\Abrechnung\Kontingente;
use App\Abrechnung\Nutzungsuebersicht;
use App\Abrechnung\Stripe\Stripeclient;
use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Abo im Produkt.
 *
 * **Die Praxis sieht Mengen, nicht Cent** (Entscheidung B11): wie viele
 * kostenpflichtige Nachrichten und Assistenzlaeufe verbraucht sind und was
 * bleibt. Was das in Euro heisst, steht auf der Rechnung -- bei Stripe, wo
 * auch die Zahlungsart und die Kuendigung liegen.
 */
final class AboController extends Controller
{
    public function __construct(
        private readonly Kontingente $kontingente,
        private readonly Nutzungsuebersicht $nutzung,
        private readonly Stripeclient $stripe,
    ) {}

    public function edit(TenantContext $mandant): Response
    {
        Gate::authorize(Ability::ManageBilling->value);

        $abo = $this->kontingente->abo();
        $enthalten = $this->kontingente->enthalten();
        $rest = $this->kontingente->rest();
        $nutzung = $this->nutzung->fuerMonat();

        return Inertia::render('settings/Abo', [
            'status' => $abo->status->value,
            'statusLabel' => $abo->status->label(),
            'testphase' => $abo->inTestphase(),
            'testphaseEndet' => $abo->trial_ends_at?->toIso8601String(),
            'periodeEndet' => $abo->period_ends_at?->toIso8601String(),
            'gekuendigtAm' => $abo->canceled_at?->toIso8601String(),

            'verbrauch' => [
                'zeitraum' => $nutzung['zeitraum'],
                'nachrichten' => $nutzung['nachrichten'],
                'kostenpflichtig' => $nutzung['kostenpflichtigeNachrichten'],
                'agentenlaeufe' => $nutzung['agentenlaeufe'],
                'angebote' => $nutzung['angebote'],
                'bilder' => $this->nutzung->bilder(
                    CarbonImmutable::now()->startOfMonth(),
                    CarbonImmutable::now()->endOfMonth(),
                ),
            ],

            'enthalten' => $enthalten,
            'rest' => $rest,
            'aufgestockt' => [
                'nachrichten' => $abo->extra_messages,
                'agentenlaeufe' => $abo->extra_agent_runs,
                'bilder' => $abo->extra_images,
            ],

            // Einzeln nachkaufbar, nicht in Bloecken (WP-31).
            'bildpreisCent' => (int) config('mrs.billing.image_price_cents'),

            'stripeAngebunden' => $this->stripe->angebunden(),
            'hatKunden' => is_string($abo->stripe_customer_id) && $abo->stripe_customer_id !== '',
        ]);
    }

    /** Fuehrt zur Kasse -- fuer das Abo oder fuer eine Aufstockung. */
    public function kasse(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageBilling->value);

        $daten = $request->validate([
            'was' => ['required', 'in:abo,nachrichten,agentenlaeufe,bilder'],

            // Bilder werden einzeln nachgekauft, nicht in Bloecken: bei zwei
            // Euro das Stueck waere ein Block von 250 eine Rechnung ueber
            // 500 Euro, die niemand wollte.
            'menge' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $praxis = $mandant->current();
        $benutzer = Auth::user();

        if (! $praxis instanceof Organization || ! $benutzer instanceof User || ! $this->stripe->angebunden()) {
            return back()->withErrors(['abo' => 'Die Abrechnung ist nicht eingerichtet.']);
        }

        $abo = $this->kontingente->abo();
        $kunde = $this->stripe->kunde($praxis, $abo->stripe_customer_id, (string) $benutzer->email);

        if ($kunde === null) {
            return back()->withErrors(['abo' => 'Stripe hat nicht geantwortet. Bitte später erneut versuchen.']);
        }

        $abo->stripe_customer_id = $kunde;
        $abo->save();

        $abschluss = $daten['was'] === 'abo';

        $preis = (string) config(match ($daten['was']) {
            'abo' => 'services.stripe.price_id',
            'bilder' => 'services.stripe.image_price_id',
            default => 'services.stripe.topup_price_id',
        });

        $adresse = $this->stripe->kasse(
            kunde: $kunde,
            preis: $preis,
            zurueck: route('abo.edit'),
            modus: $abschluss ? 'subscription' : 'payment',
            menge: $daten['was'] === 'bilder' ? (int) ($daten['menge'] ?? 1) : 1,
            artikel: $abschluss ? null : (string) $daten['was'],
        );

        if ($adresse === null) {
            return back()->withErrors(['abo' => 'Stripe hat nicht geantwortet. Bitte später erneut versuchen.']);
        }

        // **Die Aufstockung wird erst nach der Zahlung gebucht** -- der
        // Webhook meldet sie. Wer sie hier schon gutschriebe, verschenkte
        // Kontingent an jeden, der die Kasse wieder schliesst.
        return redirect()->away($adresse);
    }

    /** Rechnungen, Zahlungsart, Kuendigung -- alles im Portal von Stripe. */
    public function portal(): RedirectResponse
    {
        Gate::authorize(Ability::ManageBilling->value);

        $abo = $this->kontingente->abo();

        if (! is_string($abo->stripe_customer_id) || ! $this->stripe->angebunden()) {
            return back()->withErrors(['abo' => 'Es gibt noch kein Abo.']);
        }

        $adresse = $this->stripe->portal($abo->stripe_customer_id, route('abo.edit'));

        return $adresse === null
            ? back()->withErrors(['abo' => 'Stripe hat nicht geantwortet.'])
            : redirect()->away($adresse);
    }
}
