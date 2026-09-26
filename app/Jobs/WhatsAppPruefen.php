<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Kanaele\Kanalfehler;
use App\Kanaele\WhatsApp\WhatsAppEinrichtung;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Prueft die WhatsApp-Verbindung einer Praxis nach dem Eintragen.
 *
 * **Auf der Queue** (B2, Regel 4) -- wie die Probemail des Postfachs. Das
 * Ergebnis steht danach an der Verbindung, geprueft oder mit Grund
 * gescheitert, und die Einstellungsseite zeigt es beim naechsten Aufruf.
 */
final class WhatsAppPruefen implements ShouldQueue
{
    use Queueable;

    /** Einmal. Ein falsches Token wird beim zweiten Versuch nicht richtig. */
    public int $tries = 1;

    public function __construct(private readonly string $organisation)
    {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(TenantContext $mandant, WhatsAppEinrichtung $einrichtung): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($einrichtung): void {
            $verbindung = ChannelConnection::query()
                ->where('channel', ChannelType::WhatsApp->value)
                ->first();

            if (! $verbindung instanceof ChannelConnection) {
                return;
            }

            try {
                $einrichtung->pruefe($verbindung);
            } catch (Kanalfehler $fehler) {
                // Ein Kurzgrund, nie der Klartext des Anbieters.
                $verbindung->verified_at = null;
                $verbindung->meldeAusfall(
                    $fehler->einordnung->zustand ?? ConnectionStatus::Degraded,
                    $fehler->einordnung->kurzgrund,
                );

                return;
            }

            $verbindung->status = ConnectionStatus::Active;
            $verbindung->verified_at = CarbonImmutable::now();
            $verbindung->last_error = null;
            $verbindung->failed_at = null;
            $verbindung->save();
        });
    }
}
