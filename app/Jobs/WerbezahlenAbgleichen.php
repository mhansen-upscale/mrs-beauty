<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdAccount;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Kennzahlenabgleich;
use App\Werbung\Werbefehler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Holt Metas Zahlen eines Werbekontos (WP-28).
 *
 * Getrennt von WerbestrukturAbgleichen, obwohl beide dasselbe Konto
 * betreffen: die Zahlen kosten ein Vielfaches an Anfragen -- drei Ebenen mal
 * nachlaufendes Fenster --, und ein Rate-Limit dort soll nicht dazu fuehren,
 * dass auch die Struktur nicht ankommt.
 *
 * Queue `default`, nicht `realtime`: eine Zahl von gestern eilt nicht.
 */
final class WerbezahlenAbgleichen implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $organisation,
        private readonly string $werbekonto,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->organisation.':'.$this->werbekonto;
    }

    public function handle(TenantContext $mandant, Kennzahlenabgleich $abgleich): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($abgleich): void {
            $konto = AdAccount::query()->whereUuid($this->werbekonto)->first();

            if (! $konto instanceof AdAccount || ! $konto->istVerbunden()) {
                return;
            }

            try {
                $abgleich->gleicheAb($konto);
            } catch (Werbefehler $fehler) {
                // Der Zustand gehoert ans Werbekonto, damit die Praxis ihn
                // sieht (Regel 4).
                if ($fehler->einordnung->zustand !== null) {
                    $konto->meldeAusfall($fehler->einordnung->zustand, $fehler->einordnung->grund());
                }

                if ($fehler->einordnung->wiederholen) {
                    throw $fehler;
                }
            }
        });
    }
}
