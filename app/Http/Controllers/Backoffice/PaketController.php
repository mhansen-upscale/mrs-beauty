<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Abrechnung\Paket;
use App\Abrechnung\Stripe\Stripeclient;
use App\Http\Controllers\Controller;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Paket im Backoffice (WP-06b, B20) -- `paket.verwalten`.
 *
 * **Speichern aendert kein Paket, es legt eine Fassung an.** Ein Preis bei
 * Stripe ist unveraenderlich; wer ihn "aendert", legt am Ende zwei an und
 * merkt sich den falschen.
 *
 * **Eingabe in Euro, gespeichert in Cent.** Wer "790" in ein Cent-Feld
 * tippt, verkauft das Abo fuer 7,90 Euro.
 */
final class PaketController extends Controller
{
    /**
     * Die Felder der Eingabe -- in Euro, wo es Geld ist -- und ihre Spalte.
     *
     * @var array<string, string>
     */
    private const EURO = [
        'grundpreis' => 'base_cents',
        'einrichtung' => 'setup_cents',
        'aufstockung' => 'topup_cents',
        'bildpreis' => 'image_price_cents',
    ];

    /** @var array<string, string> */
    private const MENGEN = [
        'nachrichten' => 'included_messages',
        'agentenlaeufe' => 'included_agent_runs',
        'bilder' => 'included_images',
        'blockNachrichten' => 'topup_messages',
        'blockAgentenlaeufe' => 'topup_agent_runs',
        'testphaseTage' => 'trial_days',
    ];

    public function __construct(
        private readonly Paket $paket,
        private readonly Stripeclient $stripe,
        private readonly TenantContext $mandant,
    ) {}

    public function index(): Response
    {
        $aktuell = $this->paket->aktuell();
        $fassungen = PlanVersion::query()->orderByDesc('number')->get();
        $abos = $this->abos();

        $namen = User::query()
            ->whereIn('id', $fassungen->pluck('created_by_user_id')->filter()->all())
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $betreiber): array => [(string) $betreiber->getKey() => $betreiber->name]);

        return Inertia::render('backoffice/Paket', [
            'aktuell' => $this->werte($aktuell),
            'fassungen' => $fassungen->map(fn (PlanVersion $fassung): array => [
                'uuid' => $fassung->uuid,
                ...$this->werte($fassung),
                'stripeStand' => $fassung->stripe_state,
                'stripeFehler' => $fassung->stripe_error,
                'gilt' => $fassung->activated_at?->toIso8601String(),
                'aktuell' => $fassung->is($aktuell),
                'bestand' => $fassung->migrate_existing,
                'grund' => $fassung->reason,
                'von' => $this->urheber($fassung, $namen),
                'angelegt' => $fassung->created_at?->toIso8601String(),
                'abos' => $abos['je'][(string) $fassung->getKey()] ?? 0,
                'wartend' => $abos['wartend'][(string) $fassung->getKey()] ?? 0,
            ])->values()->all(),

            // **Wen trifft "auch den Bestand"?** Vor dem Speichern, nicht
            // danach (WP-06b Schritt 8).
            'bestand' => [
                'abgeschlossen' => $abos['abgeschlossen'],
                'testphase' => $abos['testphase'],
            ],
            'inArbeit' => $fassungen->contains(fn (PlanVersion $fassung): bool => $fassung->stripe_state === PlanVersion::AUSSTEHEND),
            'stripeAngebunden' => $this->stripe->angebunden(),

            // Stripe ist da, die Preise nicht: die Kasse oeffnet nicht, bis
            // eine Fassung sie anlegt.
            'ohneStripePreise' => $this->stripe->angebunden() && ! $aktuell->hatStripePreise(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $daten = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],

            // Ein Grundpreis von null waere ein Geschenk, kein Paket -- und
            // unter 50 Cent nimmt Stripes Kasse keine Zahlung an. Eine
            // Einrichtung von null heisst: keine.
            'grundpreis' => ['required', 'numeric', 'min:0.5', 'max:100000', 'decimal:0,2'],
            'einrichtung' => ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            'aufstockung' => ['required', 'numeric', 'min:0.5', 'max:100000', 'decimal:0,2'],
            'bildpreis' => ['required', 'numeric', 'min:0.5', 'max:100000', 'decimal:0,2'],

            'nachrichten' => ['required', 'integer', 'min:1', 'max:1000000'],
            'agentenlaeufe' => ['required', 'integer', 'min:1', 'max:1000000'],
            'bilder' => ['required', 'integer', 'min:1', 'max:100000'],
            'blockNachrichten' => ['required', 'integer', 'min:1', 'max:1000000'],
            'blockAgentenlaeufe' => ['required', 'integer', 'min:1', 'max:1000000'],
            'testphaseTage' => ['required', 'integer', 'min:1', 'max:365'],

            'bestand' => ['required', 'boolean'],
            'grund' => ['required', 'string', 'min:5', 'max:200'],
            ...BackofficeController::PASSWORT,
        ]);

        // Zwei Fassungen zugleich bei Stripe -- welche gilt dann zuerst?
        if (PlanVersion::query()->where('stripe_state', PlanVersion::AUSSTEHEND)->exists()) {
            throw ValidationException::withMessages(['name' => 'Eine Fassung wird gerade bei Stripe angelegt. Bitte warten Sie, bis sie gilt oder scheitert.']);
        }

        $werte = ['name' => trim((string) $daten['name'])];

        foreach (self::EURO as $feld => $spalte) {
            $werte[$spalte] = (int) round(((float) $daten[$feld]) * 100);
        }

        foreach (self::MENGEN as $feld => $spalte) {
            $werte[$spalte] = (int) $daten[$feld];
        }

        $aktuell = $this->paket->aktuell();
        $unveraendert = collect($werte)->every(fn (int|string $wert, string $spalte): bool => $aktuell->getAttribute($spalte) === $wert);

        // **Ausser, Stripe kennt die Fassung noch nicht** -- nach dem
        // Testbetrieb oder bei einer Installation ohne Preis-IDs. Dann ist
        // eine unveraenderte Fassung genau der Weg, die Preise anzulegen.
        if ($unveraendert && ($aktuell->hatStripePreise() || ! $this->stripe->angebunden())) {
            throw ValidationException::withMessages(['name' => 'Es hat sich nichts geändert — eine Fassung ohne Änderung legt nur neue Preise bei Stripe an.']);
        }

        /** @var User $betreiber */
        $betreiber = Auth::user();

        $this->paket->neueFassung($werte, (bool) $daten['bestand'], (string) $daten['grund'], $betreiber);

        return back();
    }

    /**
     * @return array<string, int|string>
     */
    private function werte(PlanVersion $fassung): array
    {
        return [
            'number' => $fassung->number,
            'name' => $fassung->name,
            'grundpreisCent' => $fassung->base_cents,
            'einrichtungCent' => $fassung->setup_cents,
            'aufstockungCent' => $fassung->topup_cents,
            'bildpreisCent' => $fassung->image_price_cents,
            'nachrichten' => $fassung->included_messages,
            'agentenlaeufe' => $fassung->included_agent_runs,
            'bilder' => $fassung->included_images,
            'blockNachrichten' => $fassung->topup_messages,
            'blockAgentenlaeufe' => $fassung->topup_agent_runs,
            'testphaseTage' => $fassung->trial_days,
        ];
    }

    /**
     * Wer die Fassung angelegt hat. **Ohne Kennung war es die Migration**;
     * mit einer, die keinen Namen mehr findet, ein geloeschtes Betreiberkonto
     * (28.09.2026). Den Namen von damals hat das Protokoll.
     *
     * @param  Collection<string, string>  $namen
     */
    private function urheber(PlanVersion $fassung, Collection $namen): ?string
    {
        $kennung = $fassung->getAttributes()['created_by_user_id'] ?? null;

        if (! is_string($kennung)) {
            return null;
        }

        return $namen->get($kennung) ?? 'Gelöschtes Konto';
    }

    /**
     * Wie viele Abos auf welcher Fassung stehen -- **gezaehlt, nicht
     * gelesen**, in einem Querzugriff.
     *
     * @return array{je: array<string, int>, wartend: array<string, int>, abgeschlossen: int, testphase: int}
     */
    private function abos(): array
    {
        return $this->mandant->acrossTenants(
            'Paketverwaltung zaehlt die Abos je Paketfassung (WP-06b)',
            function (): array {
                /** @var Collection<int, Subscription> $zeilen */
                $zeilen = Subscription::query()->get(['plan_version_id', 'pending_plan_version_id', 'stripe_subscription_id']);

                $je = [];
                $wartend = [];
                $abgeschlossen = 0;

                foreach ($zeilen as $abo) {
                    $fassung = (string) ($abo->getAttributes()['plan_version_id'] ?? '');
                    $je[$fassung] = ($je[$fassung] ?? 0) + 1;

                    $ausstehend = $abo->getAttributes()['pending_plan_version_id'] ?? null;

                    if (is_string($ausstehend)) {
                        $wartend[$ausstehend] = ($wartend[$ausstehend] ?? 0) + 1;
                    }

                    $abgeschlossen += is_string($abo->stripe_subscription_id) && $abo->stripe_subscription_id !== '' ? 1 : 0;
                }

                return [
                    'je' => $je,
                    'wartend' => $wartend,
                    'abgeschlossen' => $abgeschlossen,
                    'testphase' => $zeilen->count() - $abgeschlossen,
                ];
            },
        );
    }
}
