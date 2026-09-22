<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Kanaele\Eingangsverarbeitung;
use App\Models\ChannelRawEvent;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Verarbeitet ein Rohereignis.
 *
 * Schritt 3 des Ablaufs: die Zustellung ist quittiert, die Arbeit passiert
 * hier. Queue `realtime` -- jemand wartet gerade auf eine Antwort (WP-33).
 *
 * Scheitert die Verarbeitung, bleibt das Rohereignis liegen: 14 Tage lang
 * laesst es sich erneut einspielen, ohne den Anbieter um eine neue Zustellung
 * zu bitten. Genau dafuer gibt es die Tabelle.
 */
final class RohereignisVerarbeiten implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $ereignis,
        private readonly string $organisation,
    ) {
        $this->onQueue('realtime');
        $this->afterCommit();
    }

    public function handle(Eingangsverarbeitung $verarbeitung): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($organisation, function () use ($verarbeitung): void {
            $ereignis = ChannelRawEvent::query()->whereUuid($this->ereignis)->first();

            if ($ereignis instanceof ChannelRawEvent && $ereignis->processed_at === null) {
                $verarbeitung->verarbeite($ereignis);
            }
        });
    }
}
