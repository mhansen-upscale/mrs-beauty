<?php

declare(strict_types=1);

namespace App\Http\Controllers\Abrechnung;

use App\Abrechnung\Abozugang;
use App\Abrechnung\Kontingente;
use App\Abrechnung\Nutzungsuebersicht;
use App\Abrechnung\Paket;
use App\Abrechnung\Stripe\Stripeclient;
use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Subscription;
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
        private readonly Paket $paket,
    ) {}

    public function edit(TenantContext $mandant): Response
    {
        Gate::authorize(Ability::ManageBilling->value);

        $abo = $this->kontingente->abo();
        $enthalten = $this->kontingente->enthalten();
        $rest = $this->kontingente->rest();
        $nutzung = $this->nutzung->fuerMonat();

        // **Die eigene Fassung, nicht die aktuelle** (WP-06b AK 13): eine
        // Praxis im Bestandsschutz sieht nicht die Preise, die sie nicht
        // zahlt.
        $fassung = $this->paket->fuer($abo);

        return Inertia::render('settings/Abo', [
            'status' => $abo->status->value,
            'statusLabel' => $abo->status->label(),
            'testphase' => $abo->inTestphase(),
            'testphaseEndet' => $abo->trial_ends_at?->toIso8601String(),
            'periodeEndet' => $abo->period_ends_at?->toIso8601String(),
            'gekuendigtAm' => $abo->canceled_at?->toIso8601String(),
            'paket' => [
                'name' => $fassung->name,
                'grundpreisCent' => $fassung->base_cents,
            ],

            'verbrauch' => [
                'zeitraum' => $nutzung['zeitraum'],
                'nachrichten' => $nutzung['nachrichten'],
                'kostenpflichtig' => $nutzung['kostenpflichtigeNachrichten'],
                // Antworten im offenen Fenster: gezaehlt, nie gesperrt (B14).
                'servicefenster' => $nutzung['servicefenster'],
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
            'bildpreisCent' => $fassung->image_price_cents,

            // Die Praxis soll vor dem Klick wissen, was ein Block kostet.
            // Abgerechnet wird bei Stripe; die Zahl hier nennt ihn nur.
            'blockpreisCent' => $fassung->topup_cents,
            'blockmengen' => [
                'nachrichten' => $fassung->topup_messages,
                'agentenlaeufe' => $fassung->topup_agent_runs,
            ],

            // Vorerst null (B14) -- die Oberflaeche sagt dann "ohne Berechnung".
            'servicefensterpreisZehntelCent' => (int) config('mrs.billing.service_window.price_tenth_cents'),

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
        $abschluss = $daten['was'] === 'abo';

        // **Ein Abschluss zur aktuellen Fassung, eine Aufstockung zur eigenen**
        // (WP-06b): wer im Bestandsschutz ist, kauft zu seinem Preis nach.
        $fassung = $abschluss ? $this->paket->aktuell() : $this->paket->fuer($abo);

        $preis = match ($daten['was']) {
            'abo' => $fassung->stripe_price_base,
            'bilder' => $fassung->stripe_price_image,
            default => $fassung->stripe_price_topup,
        };

        if (! is_string($preis) || $preis === '') {
            return back()->withErrors(['abo' => 'Für dieses Paket fehlt der Preis bei Stripe.']);
        }

        $kunde = $this->stripe->kunde($praxis, $abo->stripe_customer_id, (string) $benutzer->email);

        if ($kunde === null) {
            return back()->withErrors(['abo' => 'Stripe hat nicht geantwortet. Bitte später erneut versuchen.']);
        }

        $abo->stripe_customer_id = $kunde;
        $abo->save();

        $adresse = $this->stripe->kasse(
            kunde: $kunde,
            preis: $preis,
            zurueck: route('abo.edit'),
            modus: $abschluss ? 'subscription' : 'payment',
            menge: $daten['was'] === 'bilder' ? (int) ($daten['menge'] ?? 1) : 1,
            artikel: $abschluss ? null : (string) $daten['was'],
            einrichtung: $abschluss ? $this->einrichtung($abo, $fassung->stripe_price_setup) : null,
        );

        if ($adresse === null) {
            return back()->withErrors(['abo' => 'Stripe hat nicht geantwortet. Bitte später erneut versuchen.']);
        }

        // **Die Aufstockung wird erst nach der Zahlung gebucht** -- der
        // Webhook meldet sie. Wer sie hier schon gutschriebe, verschenkte
        // Kontingent an jeden, der die Kasse wieder schliesst.
        return redirect()->away($adresse);
    }

    /**
     * Die Einrichtung wird einmal berechnet -- beim ersten Abschluss, nicht
     * bei jeder Rueckkehr nach einer Kuendigung.
     */
    private function einrichtung(Subscription $abo, ?string $preis): ?string
    {
        if (! is_string($preis) || $preis === '' || is_string($abo->stripe_subscription_id)) {
            return null;
        }

        return $preis;
    }

    /**
     * Die Sperrseite (WP-34c): fuer alle im Team, die das Abo nicht loesen
     * koennen -- und fuer den Betreiber in der Impersonation.
     *
     * **Kein 403.** Die Empfangskraft hat nichts falsch gemacht und kann es
     * auch nicht beheben; die Seite sagt, wer es kann.
     */
    public function gesperrt(TenantContext $mandant, Abozugang $abozugang): Response|RedirectResponse
    {
        $praxis = $mandant->current();

        if (! $praxis instanceof Organization) {
            return redirect()->route('dashboard');
        }

        $lage = $abozugang->fuer($praxis);

        if (! $lage->sperrtZugang()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('settings/AboGesperrt', [
            'praxis' => $praxis->name,
            'zugang' => $lage->value,
            'label' => $lage->label(),
            'hinweis' => $lage->hinweis(),
        ]);
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
