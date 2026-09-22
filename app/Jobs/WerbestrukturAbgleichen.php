<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdAccount;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Strukturabgleich;
use App\Werbung\Werbefehler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Holt die Kampagnenstruktur eines Werbekontos.
 *
 * **Nicht im Anfragezyklus** (Entscheidung B2, Regel 4). Der Knopf *Jetzt
 * abgleichen* stellt diesen Auftrag ein; die Seite bleibt bedienbar, auch
 * wenn Meta gerade nicht antwortet.
 *
 * **Ein Auftrag je Werbekonto, nicht einer ueber alle.** Ein Fehler bei einer
 * Praxis darf die uebrigen nicht anhalten -- und ein Konto mit abgelaufenem
 * Token wuerde einen gemeinsamen Lauf jedes Mal an derselben Stelle
 * abbrechen.
 *
 * Queue `default`: eine Struktur, die zwei Minuten spaeter steht, ist
 * dieselbe Struktur.
 */
final class WerbestrukturAbgleichen implements ShouldBeUnique, ShouldQueue
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

    /**
     * Zwei Klicks auf *Jetzt abgleichen* sind ein Abgleich.
     */
    public function uniqueId(): string
    {
        return $this->organisation.':'.$this->werbekonto;
    }

    public function handle(TenantContext $mandant, Strukturabgleich $abgleich): void
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
                // sieht (Regel 4) -- ein Eintrag im Log allein waere still.
                if ($fehler->einordnung->zustand !== null) {
                    $konto->meldeAusfall($fehler->einordnung->zustand, $fehler->einordnung->kurzgrund);
                }

                // Nur wiederholen, was sich durch Wiederholen bessert.
                if ($fehler->einordnung->wiederholen) {
                    throw $fehler;
                }
            }
        });
    }
}
