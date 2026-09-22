<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Verwaltung\Anzeigenschaltung;
use App\Werbung\Werbefehler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Traegt eine geplante Anzeige zu Meta.
 *
 * **Nie im Anfragezyklus** (Regel 4) -- und wie bei der Kampagne gilt: der
 * Auftrag sieht erst nach, ob die Anzeige schon dort steht, und legt erst
 * dann an. Metas Marketing-API kennt keinen Idempotenzschluessel, und eine
 * zweite Anzeige kostet ein zweites Mal Geld.
 */
final class AnzeigeUebertragen implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $organisation,
        private readonly string $anzeige,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->anzeige;
    }

    public function handle(TenantContext $mandant, Anzeigenschaltung $schaltung): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($schaltung): void {
            $anzeige = Ad::query()->whereUuid($this->anzeige)->first();
            $konto = AdAccount::query()->whereNull('disconnected_at')->first();

            if (! $anzeige instanceof Ad || ! $konto instanceof AdAccount) {
                return;
            }

            if ($konto->status !== ConnectionStatus::Active) {
                // Die Absicht bleibt bestehen: sobald die Verbindung steht,
                // geht sie hinaus. Der Hinweis haengt am Werbekonto.
                return;
            }

            try {
                $anzeige->external_id === '' || str_starts_with($anzeige->external_id, 'lokal-')
                    ? $schaltung->uebertrage($anzeige, $konto)
                    : $schaltung->uebertrageAenderung($anzeige, $konto);
            } catch (Werbefehler $fehler) {
                $schaltung->vermerkeFehler($anzeige, $fehler);

                if ($fehler->einordnung->wiederholen) {
                    throw $fehler;
                }
            }
        });
    }
}
