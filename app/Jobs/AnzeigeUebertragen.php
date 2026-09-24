<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Enums\SyncState;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Verwaltung\Anzeigenschaltung;
use App\Werbung\Werbefehler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    /**
     * Der letzte Versuch ist auch gescheitert.
     *
     * **Ohne diese Stelle bleibt anzeige fuer immer auf "wartet".** Die
     * Warteschlange legt den Auftrag nach dem dritten Versuch ab, und
     * niemand kommt je zurueck -- in der Oberflaeche dreht sich etwas, das
     * laengst aufgegeben wurde. Aufgefallen am 23.09.2026, nach zwanzig
     * Minuten Drehkreis.
     *
     * Der zuletzt vermerkte Grund bleibt stehen: er sagt, woran es lag.
     */
    public function failed(Throwable $grund): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($organisation, function (): void {
            $anzeige = Ad::query()->whereUuid($this->anzeige)->first();

            if (! $anzeige instanceof Ad) {
                return;
            }

            $anzeige->sync_state = SyncState::Failed;
            $anzeige->sync_error = 'Nach mehreren Versuchen aufgegeben. Zuletzt: '
                .($anzeige->sync_error ?? 'kein Grund vermerkt.');
            $anzeige->save();
        });
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
