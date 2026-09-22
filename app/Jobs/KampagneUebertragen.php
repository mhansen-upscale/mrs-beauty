<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Verwaltung\Kampagnenverwaltung;
use App\Werbung\Werbefehler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Traegt eine Kampagne oder eine Aenderung daran zu Meta.
 *
 * **Nie im Anfragezyklus** (Entscheidung B2, Regel 4). Die Oberflaeche bleibt
 * bedienbar, auch wenn Meta gerade nicht antwortet -- und eine Praxis, die
 * beim Speichern zwanzig Sekunden wartet, klickt ein zweites Mal.
 *
 * **Eindeutig je Kampagne.** Zwei Auftraege fuer dieselbe Zeile waeren im
 * schlimmsten Fall zwei Kampagnen mit zwei Budgets. Die zweite Sicherung ist
 * das Merkmal im Namen: der Auftrag sieht nach, bevor er anlegt.
 */
final class KampagneUebertragen implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $organisation,
        private readonly string $kampagne,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->organisation.':'.$this->kampagne;
    }

    public function handle(TenantContext $mandant, Kampagnenverwaltung $verwaltung): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($verwaltung): void {
            $kampagne = AdCampaign::query()->whereUuid($this->kampagne)->first();

            if (! $kampagne instanceof AdCampaign) {
                return;
            }

            $konto = AdAccount::query()->whereKey($kampagne->getAttributes()['ad_account_id'])->first();

            if (! $konto instanceof AdAccount || ! $konto->istVerbunden()) {
                return;
            }

            try {
                // Noch nie bei Meta gewesen: anlegen. Sonst aendern.
                str_starts_with($kampagne->external_id, 'lokal-')
                    ? $verwaltung->uebertrage($kampagne, $konto)
                    : $verwaltung->uebertrageAenderung($kampagne, $konto);
            } catch (Werbefehler $fehler) {
                // Der Zustand der Verbindung gehoert ans Werbekonto, der
                // fachliche Grund an die Kampagne -- die Praxis kann nur den
                // zweiten beheben.
                if ($fehler->einordnung->zustand !== null) {
                    $konto->meldeAusfall($fehler->einordnung->zustand, $fehler->einordnung->kurzgrund);
                }

                $verwaltung->vermerkeFehler($kampagne, $fehler);

                if ($fehler->einordnung->wiederholen) {
                    throw $fehler;
                }
            }
        });
    }
}
