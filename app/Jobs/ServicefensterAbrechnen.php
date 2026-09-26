<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Abrechnung\Servicefensterabrechnung;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Rechnet die Antworten im Service-Fenster eines Monats ab (Entscheidung B14).
 *
 * **In der Warteschlange, nicht im Planer** (Regel 4): ein schreibender
 * Aufruf an Stripe, mit Idempotenzschluessel und Wiederholung. Faellt Stripe
 * aus, wartet die Rechnung -- und keine andere Praxis auf diese.
 */
final class ServicefensterAbrechnen implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    /**
     * @param  string  $monat  `Y-m`, ein abgeschlossener Monat
     */
    public function __construct(
        public readonly string $organisation,
        public readonly string $monat,
    ) {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return $this->organisation.'-'.$this->monat;
    }

    public function handle(TenantContext $mandant, Servicefensterabrechnung $abrechnung): void
    {
        $praxis = Organization::query()->whereUuid($this->organisation)->first();

        if (! $praxis instanceof Organization) {
            return;
        }

        $ergebnis = $mandant->runAs($praxis, fn (): string => $abrechnung->rechneAb($this->monat));

        Log::info('Servicefenster abgerechnet', ['monat' => $this->monat, 'ergebnis' => $ergebnis]);
    }
}
