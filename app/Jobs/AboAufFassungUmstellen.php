<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Abrechnung\Paket;
use App\Abrechnung\Stripe\Stripeclient;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Support\Fehlereinordnung;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Stellt das Abo einer Praxis bei Stripe auf eine neue Paketfassung um
 * (WP-06b, B20 -- nur wenn fuer diese Aenderung so entschieden).
 *
 * **Ohne anteilige Verrechnung:** der laufende Zeitraum ist bezahlt, die
 * naechste Rechnung kommt zum neuen Preis. Die Fassung am Abo setzt nicht
 * dieser Auftrag, sondern der Webhook, wenn Stripe den neuen Preis meldet --
 * die Kontingente wechseln zur naechsten Periode (WP-06 AK 13).
 *
 * Ein Abo ohne Stripe (Testphase) hat keine Position umzustellen; es wechselt
 * hier direkt.
 */
final class AboAufFassungUmstellen implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly string $organisation,
        public readonly string $fassung,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->fassung.'-'.$this->organisation;
    }

    public function handle(TenantContext $mandant, Stripeclient $stripe, Paket $paket, AuditLogger $protokoll): void
    {
        $praxis = Organization::query()->whereUuid($this->organisation)->first();
        $fassung = PlanVersion::query()->whereUuid($this->fassung)->first();

        if (! $praxis instanceof Organization || ! $fassung instanceof PlanVersion || ! $fassung->gilt()) {
            return;
        }

        $mandant->runAs($praxis, function () use ($stripe, $paket, $protokoll, $fassung): void {
            $abo = Subscription::query()->first();

            if (! $abo instanceof Subscription || ($abo->getAttributes()['plan_version_id'] ?? null) === $fassung->getKey()) {
                return;
            }

            if (! is_string($abo->stripe_subscription_id) || $abo->stripe_subscription_id === '' || $fassung->stripe_price_base === null) {
                $paket->wechsle($abo, $fassung);

                return;
            }

            try {
                $stand = $this->antwort($stripe->abo($abo->stripe_subscription_id));
                $position = data_get($stand, 'items.data.0.id');

                if (! is_string($position)) {
                    throw new DomainException('Das Abo hat bei Stripe keine Position.');
                }

                if (data_get($stand, 'items.data.0.price.id') !== $fassung->stripe_price_base) {
                    $this->antwort($stripe->stelleAboUm(
                        $abo->stripe_subscription_id,
                        $position,
                        $fassung->stripe_price_base,
                        $this->fassung.'-'.$abo->uuid,
                    ));
                }
            } catch (DomainException $ablehnung) {
                $this->scheitere($abo, $fassung, $ablehnung->getMessage(), $protokoll);

                return;
            }

            // Bis Stripe den neuen Preis meldet, wartet die Fassung am Abo.
            $abo->forceFill(['pending_plan_version_id' => $fassung->getKey()])->save();
        });
    }

    public function failed(?Throwable $fehler): void
    {
        $praxis = Organization::query()->whereUuid($this->organisation)->first();
        $fassung = PlanVersion::query()->whereUuid($this->fassung)->first();

        if (! $praxis instanceof Organization || ! $fassung instanceof PlanVersion) {
            return;
        }

        app(TenantContext::class)->runAs($praxis, function () use ($fassung): void {
            $abo = Subscription::query()->first();

            if ($abo instanceof Subscription) {
                $this->scheitere($abo, $fassung, 'Stripe war auch nach mehreren Versuchen nicht erreichbar.', app(AuditLogger::class));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function antwort(Response $antwort): array
    {
        if ($antwort->successful()) {
            return (array) $antwort->json();
        }

        /** @var array<string, mixed> $koerper */
        $koerper = (array) $antwort->json();
        $einordnung = Fehlereinordnung::ausStripeAntwort($antwort->status(), $koerper);

        if ($einordnung->wiederholen) {
            throw new RuntimeException("Stripe antwortet mit {$antwort->status()}; die Umstellung wird wiederholt.");
        }

        throw new DomainException($einordnung->grund());
    }

    /**
     * Endgueltig -- das Abo bleibt unter seiner Fassung, und die Praxis kann
     * nachlesen, warum. Die Paketseite zaehlt, wie viele Abos noch nicht
     * umgestellt sind.
     */
    private function scheitere(Subscription $abo, PlanVersion $fassung, string $grund, AuditLogger $protokoll): void
    {
        $protokoll->record(
            ereignis: AuditEvent::SubscriptionPlanChangeFailed,
            gegenstand: $abo,
            kontext: ['nach' => $fassung->number, 'grund' => mb_substr($grund, 0, 200)],
        );
    }
}
