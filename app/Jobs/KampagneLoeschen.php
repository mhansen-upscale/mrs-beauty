<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\Organization;
use App\Support\Abbruchvermerk;
use App\Tenancy\TenantContext;
use App\Werbung\Verwaltung\Kampagnenverwaltung;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entfernt eine selbst angelegte Kampagne -- bei Meta und bei uns.
 *
 * **Nie im Anfragezyklus** (Regel 4): der Aufruf kann haengen, und eine
 * Oberflaeche, die dabei stehenbleibt, laesst niemanden wissen, ob geloescht
 * wurde.
 *
 * **Erst bei Meta, dann bei uns.** Andersherum bliebe bei einem Abbruch eine
 * Kampagne bei Meta stehen, die niemand mehr sieht -- und die weiter Geld
 * ausgeben koennte, wenn jemand sie dort startet.
 */
final class KampagneLoeschen implements ShouldBeUnique, ShouldQueue
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
        return $this->organisation.':loeschen:'.$this->kampagne;
    }

    /**
     * Der letzte Versuch ist auch gescheitert.
     *
     * Hier bleibt bewusst alles stehen: eine halb geloeschte Kampagne ist
     * schlimmer als eine, die noch da ist. Der naechste Versuch faengt von
     * vorn an, und das Entfernen bei Meta ist wiederholbar.
     */
    public function failed(Throwable $grund): void
    {
        Log::error('Kampagne konnte nicht geloescht werden.', [
            'kampagne' => $this->kampagne,
            'art' => $grund::class,
            'meldung' => $grund->getMessage(),
        ]);

        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        // **Der Vermerk geht zurueck.** Eine Zeile, die auf ewig "wird
        // entfernt" sagt, ist schlimmer als eine, die wieder normal dasteht:
        // die zweite kann man erneut loeschen, die erste nur noch ansehen.
        app(TenantContext::class)->runAs($organisation, function () use ($grund): void {
            $kampagne = AdCampaign::query()->whereUuid($this->kampagne)->first();

            if (! $kampagne instanceof AdCampaign) {
                return;
            }

            $kampagne->deleting_at = null;
            $kampagne->sync_error = Abbruchvermerk::satz(
                $grund,
                null,
                'Kampagne löschen',
                $this->kampagne,
            );
            $kampagne->save();
        });
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

            // **Fremdes fassen wir nicht an** (C9). Auch nicht auf Zuruf: was
            // die Praxis vor uns angelegt hat, gehoert ihr und wird in Metas
            // Oberflaeche entfernt, nicht hier.
            if (! $kampagne->managed_by_us) {
                return;
            }

            $konto = AdAccount::query()->whereNull('disconnected_at')->first();

            if ($konto instanceof AdAccount) {
                $verwaltung->entferneBeiMeta($kampagne, $konto);
            }

            $gruppen = AdSet::query()->where('ad_campaign_id', $kampagne->getKey())->get();

            Ad::query()->whereIn('ad_set_id', $gruppen->modelKeys())->delete();
            AdSet::query()->whereKey($gruppen->modelKeys())->delete();
            $kampagne->delete();
        });
    }
}
