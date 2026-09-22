<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\WaitlistTrigger;
use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Practitioner;
use App\Tenancy\TenantContext;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Warteliste\Vergabelauf;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bietet einen frei gewordenen Slot der Warteliste an.
 *
 * **Nicht im Anfragezyklus** (Regel 4): eine Absage am Telefon darf nicht
 * darauf warten, dass eine WhatsApp-Nachricht hinausgeht. Und nicht in der
 * Transaktion der Absage: ein Angebot auf einen Termin, dessen Absage noch
 * zurueckgerollt werden koennte, waere eines zu viel.
 *
 * Queue `default`: eine Luecke, die zehn Sekunden spaeter angeboten wird, ist
 * dieselbe Luecke.
 */
final class LueckeFuellen implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $organisation,
        private readonly string $art,
        private readonly string $behandler,
        private readonly string $standort,
        private readonly string $beginn,
        private readonly string $ende,
        private readonly string $blockiertVon,
        private readonly string $blockiertBis,
        private readonly WaitlistTrigger $ausloeser,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(TenantContext $mandant, Vergabelauf $vergabe): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($vergabe): void {
            $art = AppointmentType::query()->whereUuid($this->art)->first();
            $behandler = Practitioner::query()->whereUuid($this->behandler)->first();
            $standort = Location::query()->whereUuid($this->standort)->first();

            if (! $art instanceof AppointmentType
                || ! $behandler instanceof Practitioner
                || ! $standort instanceof Location) {
                return;
            }

            $vergabe->biete(new Slotvorschlag(
                art: $art,
                behandler: $behandler,
                standort: $standort,
                blockedFrom: CarbonImmutable::parse($this->blockiertVon),
                blockedUntil: CarbonImmutable::parse($this->blockiertBis),
                startsAt: CarbonImmutable::parse($this->beginn),
                endsAt: CarbonImmutable::parse($this->ende),
            ), $this->ausloeser);
        });
    }
}
