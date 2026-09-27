<?php

declare(strict_types=1);

namespace App\Backoffice;

use App\Abrechnung\Nutzungsuebersicht;
use App\Abrechnung\Paket;
use App\Abrechnung\Stripe\Stripeclient;
use App\Enums\ConnectionStatus;
use App\Enums\SubscriptionAccess;
use App\Enums\SubscriptionStatus;
use App\Models\AdAccount;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\ChannelConnection;
use App\Models\ChannelRawEvent;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Was der Betreiber ueber seine Praxen sehen darf.
 *
 * **Zustaende und Zahlen. Inhalte nie.** Kein Kontaktname, kein
 * Nachrichtentext, kein Termin -- auch nicht maskiert. Wer wirklich in eine
 * Praxis hineinsehen muss, geht ueber die Impersonation aus WP-05: mit
 * Begruendung, mit Freigabe durch die Praxis, im Protokoll und sichtbar fuer
 * beide Seiten.
 *
 * Das ist keine technische Huerde, sondern die Zusage des Produkts. Eine
 * Praxis, die die WhatsApp-Nachrichten ihrer Patientinnen ueber uns fuehrt,
 * muss sich darauf verlassen koennen, dass "der Anbieter kann alles lesen"
 * nicht stimmt.
 *
 * **Zahlen je Mandant, nie je Person.** "Drei Nachrichten heute" ist bei
 * einer Praxis mit einer Patientin eine Aussage ueber diese Patientin.
 *
 * **Gruppiert gezaehlt** (WP-34c): eine Abfrage je Quelle, nicht vier je
 * Praxis.
 */
final class Mandantenuebersicht
{
    /** So viele Eingriffe zeigt das Blatt. Der Rest steht im Protokoll der Praxis. */
    private const EINGRIFFE = 10;

    /** Die Testphase einer Praxis ohne Abo-Zeile -- einmal je Aufruf gelesen. */
    private ?int $testphaseTage = null;

    public function __construct(
        private readonly TenantContext $mandant,
        private readonly Nutzungsuebersicht $nutzung,
        private readonly Stripeclient $stripe,
        private readonly Paket $paket,
    ) {}

    /**
     * Alle Praxen mit ihren Kennzahlen.
     *
     * @return list<array<string, mixed>>
     */
    public function liste(string $suche = '', ?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();
        $begriff = trim($suche);

        /** @var list<array<string, mixed>> */
        return $this->mandant->acrossTenants(
            'Backoffice zeigt dem Betreiber die Liste seiner Mandanten (WP-34)',
            function () use ($begriff, $jetzt): array {
                $praxen = Organization::query()
                    ->when($begriff !== '', fn ($abfrage) => $abfrage
                        ->where('name', 'like', '%'.$begriff.'%')
                        ->orWhere('slug', 'like', '%'.$begriff.'%'))
                    ->orderBy('name')
                    ->limit(200)
                    ->get();

                $zahlen = $this->zaehle($praxen->modelKeys(), $jetzt);

                return $praxen
                    ->map(fn (Organization $praxis): array => $this->zeile($praxis, $jetzt, $zahlen))
                    ->values()
                    ->all();
            },
        );
    }

    /**
     * Das Blatt einer einzelnen Praxis.
     *
     * @return array<string, mixed>
     */
    public function blatt(Organization $praxis, ?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();

        /** @var array<string, mixed> */
        return $this->mandant->runAs($praxis, function () use ($praxis, $jetzt): array {
            $nutzung = $this->nutzung->fuerMonat($jetzt);

            return array_merge($this->zeile($praxis, $jetzt, $this->zaehle([$praxis->getKey()], $jetzt)), [
                'verbrauch' => [
                    'nachrichten' => $nutzung['nachrichten'],
                    'kostenpflichtig' => $nutzung['kostenpflichtigeNachrichten'],
                    'agentenlaeufe' => $nutzung['agentenlaeufe'],
                    'angebote' => $nutzung['angebote'],
                ],

                // Nicht `abo` -- das traegt in der Zeile schon den Status.
                'aboStand' => $this->abo(Subscription::query()->first()),

                // Betriebslage je Mandant (WP-33) -- Zustaende, keine Inhalte.
                'stoerungen' => [
                    'kanaele' => ChannelConnection::query()
                        ->whereIn('status', [
                            ConnectionStatus::Expired->value,
                            ConnectionStatus::Degraded->value,
                            ConnectionStatus::Suspended->value,
                        ])
                        ->get()
                        ->map(fn (ChannelConnection $verbindung): array => [
                            'kanal' => $verbindung->channel->label(),
                            'status' => $verbindung->status->label(),
                            'grund' => $verbindung->last_error,
                        ])
                        ->values()
                        ->all(),

                    'kalender' => CalendarConnection::query()->where('status', '!=', 'active')->count(),

                    // Zahl, kein Inhalt -- wie alles hier.
                    'werbung' => AdAccount::query()
                        ->whereNull('disconnected_at')
                        ->whereIn('status', [
                            ConnectionStatus::Expired->value,
                            ConnectionStatus::Degraded->value,
                            ConnectionStatus::Suspended->value,
                        ])
                        ->count(),

                    'ereignisse' => ChannelRawEvent::query()->offen()->count(),
                ],
            ]);
        });
    }

    /**
     * Das Abo im Blatt (WP-34c): Zustand, Fristen, Eingriffe.
     *
     * Die Kundenkennung bei Stripe verlaesst das Haus nur als Link ins
     * Stripe-Dashboard -- sie ist keine Angabe ueber eine Patientin, aber auch
     * nichts, was die Oberflaeche braucht.
     *
     * @return array<string, mixed>
     */
    private function abo(?Subscription $abo): array
    {
        $kunde = $abo?->stripe_customer_id;
        $testmodus = str_starts_with((string) config('services.stripe.key'), 'sk_test_');
        $fassung = $this->paket->fuer($abo);

        return [
            // Unter welcher Paketfassung die Praxis rechnet (WP-06b).
            'fassung' => $fassung->number,
            'fassungName' => $fassung->name,
            'grundpreisCent' => $fassung->base_cents,

            'status' => ($abo->status ?? SubscriptionStatus::Trialing)->value,
            'statusLabel' => ($abo->status ?? SubscriptionStatus::Trialing)->label(),
            'mitStripeAbo' => is_string($abo?->stripe_subscription_id) && $abo->stripe_subscription_id !== '',
            'periodeBeginnt' => $abo?->period_starts_at?->toIso8601String(),
            'periodeEndet' => $abo?->period_ends_at?->toIso8601String(),
            'testphaseEndet' => $abo?->testphasenende()->toIso8601String(),
            'pausiertSeit' => $abo?->paused_at?->toIso8601String(),
            'pausiertBis' => $abo?->pause_resumes_at?->toIso8601String(),
            'kuendigungZumPeriodenende' => (bool) ($abo->cancel_at_period_end ?? false),
            'kuendigungZum' => $abo?->cancel_at?->toIso8601String(),
            'gratismonatBis' => $abo?->discount_ends_at?->toIso8601String(),

            // **Ohne Stripe ist Testbetrieb** -- die Oberflaeche sagt es, statt
            // Knoepfe anzubieten, die bei Stripe nichts ausloesen.
            'stripeAngebunden' => $this->stripe->angebunden(),
            'stripeLink' => is_string($kunde) && $kunde !== ''
                ? 'https://dashboard.stripe.com/'.($testmodus ? 'test/' : '').'customers/'.rawurlencode($kunde)
                : null,

            'eingriffe' => SubscriptionChange::query()
                ->latest()
                ->limit(self::EINGRIFFE)
                ->get()
                ->map(fn (SubscriptionChange $eingriff): array => [
                    'uuid' => $eingriff->uuid,
                    'aktion' => $eingriff->action->value,
                    'aktionLabel' => $eingriff->action->label(),
                    'status' => $eingriff->status->value,
                    'statusLabel' => $eingriff->status->label(),
                    'grund' => $eingriff->reason,
                    'fehler' => $eingriff->error,
                    'ohneStripe' => $eingriff->ohneStripe(),
                    'angelegt' => $eingriff->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Die Zaehlungen fuer einige Praxen -- **eine Abfrage je Quelle**.
     *
     * Laeuft im Querzugriff der Liste oder im Mandanten des Blatts; die
     * Abfragen schraenken ausdruecklich auf die Kennungen ein.
     *
     * @param  array<int, mixed>  $kennungen
     * @return array{benutzer: Collection<string, int>, kontakte: Collection<string, int>, termine: Collection<string, int>, abos: Collection<string, Subscription>}
     */
    private function zaehle(array $kennungen, CarbonImmutable $jetzt): array
    {
        $gruppiert = fn ($abfrage): Collection => $abfrage
            ->whereIn('organization_id', $kennungen)
            ->selectRaw('organization_id, count(*) as anzahl')
            ->groupBy('organization_id')
            ->pluck('anzahl', 'organization_id')
            ->map(fn (mixed $anzahl): int => (int) $anzahl);

        return [
            'benutzer' => $gruppiert(User::query()->whereNull('deactivated_at')),
            'kontakte' => $gruppiert(Contact::query()),
            'termine' => $gruppiert(Appointment::query()->where('starts_at', '>=', $jetzt->subDays(30))),
            'abos' => Subscription::query()
                ->whereIn('organization_id', $kennungen)
                ->get()
                ->keyBy(fn (Subscription $abo): string => (string) $abo->getAttribute('organization_id')),
        ];
    }

    /**
     * Eine Zeile der Liste.
     *
     * @param  array{benutzer: Collection<string, int>, kontakte: Collection<string, int>, termine: Collection<string, int>, abos: Collection<string, Subscription>}  $zahlen
     * @return array<string, mixed>
     */
    private function zeile(Organization $praxis, CarbonImmutable $jetzt, array $zahlen): array
    {
        $kennung = (string) $praxis->getKey();
        $abo = $zahlen['abos']->get($kennung);
        $zustand = $abo instanceof Subscription ? $abo->status : SubscriptionStatus::Trialing;

        /** @var CarbonImmutable $angelegt */
        $angelegt = $praxis->getAttribute('created_at');

        // Ohne Abo-Zeile ist eine Praxis in der Testphase -- ab ihrem Anlegen.
        $testphaseEndet = $abo instanceof Subscription
            ? $abo->testphasenende()
            : $angelegt->addDays($this->testphaseTage ??= $this->paket->aktuell()->trial_days);

        $zugang = $abo instanceof Subscription
            ? $abo->zugang($jetzt)
            : ($testphaseEndet->greaterThan($jetzt) ? SubscriptionAccess::Trial : SubscriptionAccess::TrialExpired);

        return [
            'uuid' => $praxis->uuid,
            'name' => $praxis->name,
            'slug' => $praxis->slug,
            'gesperrt' => $praxis->suspended_at !== null,
            'gesperrtSeit' => $praxis->suspended_at?->toIso8601String(),
            'angelegt' => $angelegt->toIso8601String(),
            'benutzer' => $zahlen['benutzer']->get($kennung, 0),
            'kontakte' => $zahlen['kontakte']->get($kennung, 0),
            'termine30' => $zahlen['termine']->get($kennung, 0),
            'abo' => $zustand->value,
            'aboLabel' => $zustand->label(),
            'periodeEndet' => $abo?->period_ends_at?->toIso8601String(),

            // Die eine Stelle, die jede Abo-Sperre kennt (WP-34c).
            'zugang' => $zugang->value,
            'zugangLabel' => $zugang->label(),
            'testphaseEndet' => $zugang === SubscriptionAccess::Trial || $zugang === SubscriptionAccess::TrialExpired
                ? $testphaseEndet->toIso8601String()
                : null,
        ];
    }
}
