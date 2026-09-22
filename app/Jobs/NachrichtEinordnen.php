<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Agentenlauf;
use App\Models\Message;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Laesst den Agenten ueber eine eingehende Nachricht laufen.
 *
 * **Nie im Anfragezyklus** (Regel 4). Ein Sprachmodell antwortet in Sekunden,
 * manchmal gar nicht -- die Inbox bleibt bedienbar, und die Nachricht steht
 * darin, auch wenn der Agent nichts zustande bringt.
 *
 * Queue `realtime`: ein Vorschlag, der erst in einer Stunde im Eingabefeld
 * steht, ist keiner.
 *
 * **Einmal je Nachricht** -- der Unique-Index auf `agent_runs` entscheidet,
 * nicht die Abfrage davor (Entscheidung A13).
 */
final class NachrichtEinordnen implements ShouldQueue
{
    use Queueable;

    /**
     * Zweimal. Ein Modell, das gerade nicht antwortet, antwortet oft gleich
     * danach; ein abgelehnter Aufruf wird beim zwanzigsten Versuch nicht
     * angenommen.
     */
    public int $tries = 2;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30];
    }

    public function __construct(
        private readonly string $nachricht,
        private readonly string $organisation,
    ) {
        $this->onQueue('realtime');

        // Der Auftrag entsteht in der Transaktion der Aufrufstelle; ein
        // schnellerer Arbeiter faende die Zeile sonst nicht.
        $this->afterCommit();
    }

    public function handle(TenantContext $mandant, Agentenlauf $lauf): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($lauf): void {
            $nachricht = Message::query()
                ->whereUuid($this->nachricht)
                ->with('conversation')
                ->first();

            if ($nachricht instanceof Message) {
                $lauf->fuer($nachricht);
            }
        });
    }
}
