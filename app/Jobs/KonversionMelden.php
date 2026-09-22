<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Attribution\Meta\Conversionsversand;
use App\Attribution\Meta\Konversionsereignis;
use App\Models\Appointment;
use App\Models\AttributionTouch;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Meldet eine Buchung als `Lead` an Metas Conversions API.
 *
 * **Nie im Anfragezyklus** (Regel 4): eine Buchung darf nicht darauf warten,
 * dass Meta antwortet -- und wenn Meta gerade nicht antwortet, ist der Termin
 * trotzdem gebucht.
 *
 * **Dieselbe `event_id` wie im Pixel.** Ohne sie zaehlt Meta doppelt; mit ihr
 * ist es dasselbe Ereignis, einmal aus dem Browser und einmal vom Server.
 */
final class KonversionMelden implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $organisation,
        private readonly string $termin,
        private readonly string $ereignisId,
        private readonly ?string $besucher = null,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->ereignisId;
    }

    public function handle(TenantContext $mandant, Conversionsversand $versand): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($organisation, $versand): void {
            $termin = Appointment::query()->whereUuid($this->termin)->with('contact')->first();

            if (! $termin instanceof Appointment) {
                return;
            }

            try {
                $versand->sende($organisation, new Konversionsereignis(
                    name: 'Lead',
                    ereignisId: $this->ereignisId,
                    zeitpunkt: $termin->created_at ?? CarbonImmutable::now(),
                    kontakt: $termin->contact,
                    klickId: $this->klickId(),
                ));
            } catch (Werbefehler $fehler) {
                // Eine Meldung, die nicht ankommt, kostet eine Zuordnung --
                // keinen Termin. Wiederholt wird nur, was sich durch
                // Wiederholen bessert.
                if ($fehler->einordnung->wiederholen) {
                    throw $fehler;
                }
            }
        });
    }

    /**
     * Metas Klick-Kennung in ihrer Form: `fb.1.<zeitstempel>.<fbclid>`.
     *
     * Der Zeitstempel ist der des Klicks, nicht der der Buchung -- er steht
     * an der Beruehrung.
     */
    private function klickId(): ?string
    {
        if ($this->besucher === null) {
            return null;
        }

        $touch = AttributionTouch::query()
            ->fuerBesucher($this->besucher)
            ->whereNotNull('click_id')
            ->orderByDesc('occurred_at')
            ->first();

        if (! $touch instanceof AttributionTouch || $touch->click_id === null) {
            return null;
        }

        return 'fb.1.'.($touch->occurred_at->getTimestamp() * 1000).'.'.$touch->click_id;
    }
}
