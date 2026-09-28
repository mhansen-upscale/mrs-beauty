<?php

declare(strict_types=1);

namespace App\Abrechnung;

use App\Abrechnung\Stripe\Stripeclient;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Jobs\AboAufFassungUmstellen;
use App\Jobs\PaketfassungAnlegen;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Das Paket -- **die eine Stelle fuer Name, Preise und Kontingente** (WP-06b).
 *
 * Bis hier standen die Werte in `config/mrs.php` und die Preis-IDs in
 * `config/services.php`, dazu der Satz "Wer einen aendert, aendert beide".
 * Jetzt sind sie Datenbestand in Fassungen (B20): **ein Preis bei Stripe ist
 * unveraenderlich, also ist es die Fassung auch.**
 *
 * - `aktuell()` ist die juengste Fassung, die gilt -- nur sie sieht eine neue
 *   Praxis in der Kasse.
 * - `fuer($abo)` ist die Fassung, zu der ein Abo abgeschlossen wurde
 *   (Bestandsschutz).
 *
 * **Eine Stufe bleibt eine Stufe** (B10): ein Paket, keine Auswahl.
 *
 * **Testbetrieb ohne Stripe:** eine neue Fassung gilt sofort, ohne
 * Stripe-Preise -- sonst gaelte sie nie, und das Backoffice liesse sich nicht
 * ausprobieren.
 */
final class Paket
{
    public function __construct(
        private readonly TenantContext $mandant,
        private readonly Stripeclient $stripe,
        private readonly AuditLogger $protokoll,
    ) {}

    public function aktuell(): PlanVersion
    {
        return $this->geltende() ?? throw new RuntimeException('Es gibt keine gueltige Paketfassung -- die Migration 2026_09_27_140000 legt Fassung 1 an.');
    }

    /**
     * Die geltende Fassung -- oder null, wo ein Fehlen kein Fehler ist: die
     * Startseite zeigt dann keine Preise, statt abzustuerzen (WP-38).
     */
    public function geltende(): ?PlanVersion
    {
        return PlanVersion::query()
            ->whereNotNull('activated_at')
            ->orderByDesc('number')
            ->first();
    }

    /** Die Fassung eines Abos -- ohne Abo oder ohne Zuordnung die aktuelle. */
    public function fuer(?Subscription $abo): PlanVersion
    {
        $kennung = $abo?->getAttributes()['plan_version_id'] ?? null;

        if (is_string($kennung)) {
            $fassung = PlanVersion::query()->whereKey($kennung)->first();

            if ($fassung instanceof PlanVersion) {
                return $fassung;
            }
        }

        return $this->aktuell();
    }

    /**
     * Legt eine neue Fassung an (WP-06b).
     *
     * **Speichern aendert kein Paket**, es entsteht eine neue Fassung. Mit
     * Stripe legt der Auftrag PaketfassungAnlegen die Preise an -- nie im
     * Anfragezyklus (Regel 4) --, und erst danach gilt sie. Ohne Stripe gilt
     * sie sofort.
     *
     * @param  array<string, int|string>  $werte  Name, Preise in Cent, Kontingente, Testphase
     */
    public function neueFassung(array $werte, bool $bestandUmstellen, string $grund, User $betreiber): PlanVersion
    {
        $vorige = $this->aktuell();

        $fassung = new PlanVersion;
        $fassung->forceFill([
            ...$werte,
            'number' => ((int) PlanVersion::query()->max('number')) + 1,
            'stripe_product_id' => $vorige->stripe_product_id,
            'stripe_state' => PlanVersion::AUSSTEHEND,
            'migrate_existing' => $bestandUmstellen,
            'reason' => $grund,
            'created_by_user_id' => $betreiber->getKey(),
        ]);
        $fassung->save();

        $this->protokoll->record(
            ereignis: AuditEvent::PlanVersionCreated,
            gegenstand: $fassung,
            kontext: ['fassung' => $fassung->number, 'bestand' => $bestandUmstellen, 'ohne_stripe' => ! $this->stripe->angebunden()],
            begruendung: $grund,
            ohneOrganisation: true,
        );

        if (! $this->stripe->angebunden()) {
            $this->giltAb($fassung);

            return $fassung;
        }

        PaketfassungAnlegen::dispatch((string) $fassung->uuid);

        return $fassung->refresh();
    }

    /**
     * Ab jetzt gilt die Fassung fuer neue Abschluesse -- und, wenn so
     * entschieden, fuer den Bestand.
     */
    public function giltAb(PlanVersion $fassung): void
    {
        $fassung->stripe_state = PlanVersion::BEREIT;
        $fassung->stripe_error = null;
        $fassung->activated_at = CarbonImmutable::now();
        $fassung->save();

        $this->protokoll->record(
            ereignis: AuditEvent::PlanVersionReady,
            gegenstand: $fassung,
            kontext: ['fassung' => $fassung->number],
            ohneOrganisation: true,
        );

        $this->nimmTestphasenMit($fassung);

        if ($fassung->migrate_existing) {
            $this->stelleBestandUm($fassung);
        }
    }

    /**
     * **Eine Testphase ist kein Abschluss** (WP-06b). Bestandsschutz gilt fuer
     * das, was eine Praxis bei Stripe abgeschlossen hat; wer noch testet,
     * schliesst ohnehin zur aktuellen Fassung ab -- und soll bis dahin deren
     * Kontingente sehen, nicht die einer Fassung, die niemand mehr kaufen
     * kann.
     */
    private function nimmTestphasenMit(PlanVersion $fassung): void
    {
        $praxen = $this->mandant->acrossTenants(
            'Neue Paketfassung gilt fuer alle Praxen in der Testphase (WP-06b)',
            fn () => Subscription::query()
                ->where(fn ($abfrage) => $abfrage->whereNull('stripe_subscription_id')->orWhere('stripe_subscription_id', ''))
                ->where(fn ($abfrage) => $abfrage->whereNull('plan_version_id')->orWhere('plan_version_id', '!=', $fassung->getKey()))
                ->pluck('organization_id')
                ->all(),
        );

        foreach (Organization::query()->whereIn('id', $praxen)->get() as $praxis) {
            $this->mandant->runAs($praxis, function () use ($fassung): void {
                $abo = Subscription::query()->first();

                if ($abo instanceof Subscription) {
                    $this->wechsle($abo, $fassung);
                }
            });
        }
    }

    /**
     * Stellt jedes bestehende Abo auf die Fassung um (B20, nur wenn so
     * entschieden).
     *
     * Mit Stripe je Abo ein Auftrag; die Fassung am Abo setzt der Webhook,
     * wenn Stripe den neuen Preis meldet -- die Kontingente ab der naechsten
     * Periode. **Ohne Stripe** (Testbetrieb) gilt sie sofort: es gibt keine
     * Periode, die sie abwarten koennte.
     */
    public function stelleBestandUm(PlanVersion $fassung): void
    {
        $praxen = $this->mandant->acrossTenants(
            'Paketfassung wird auf den Bestand aller Mandanten umgestellt (WP-06b)',
            fn () => Subscription::query()
                ->where(fn ($abfrage) => $abfrage->whereNull('plan_version_id')->orWhere('plan_version_id', '!=', $fassung->getKey()))
                ->pluck('organization_id')
                ->all(),
        );

        $this->protokoll->record(
            ereignis: AuditEvent::PlanMigrationRequested,
            gegenstand: $fassung,
            kontext: ['fassung' => $fassung->number, 'abos' => count($praxen)],
            ohneOrganisation: true,
        );

        foreach (Organization::query()->whereIn('id', $praxen)->get() as $praxis) {
            if ($this->stripe->angebunden()) {
                AboAufFassungUmstellen::dispatch((string) $praxis->uuid, (string) $fassung->uuid);

                continue;
            }

            $this->mandant->runAs($praxis, function () use ($fassung): void {
                $abo = Subscription::query()->first();

                if ($abo instanceof Subscription) {
                    $this->wechsle($abo, $fassung);
                }
            });
        }
    }

    /**
     * Das Abo gilt ab jetzt unter dieser Fassung -- protokolliert beim
     * Mandanten, damit die Praxis nachlesen kann, warum sich Preis oder
     * Kontingent geaendert haben.
     */
    public function wechsle(Subscription $abo, PlanVersion $fassung): void
    {
        $vorher = $this->fuer($abo);

        $abo->forceFill(['plan_version_id' => $fassung->getKey(), 'pending_plan_version_id' => null])->save();

        $this->protokoll->record(
            ereignis: AuditEvent::SubscriptionPlanChanged,
            gegenstand: $abo,
            kontext: ['von' => $vorher->number, 'nach' => $fassung->number],
        );
    }
}
