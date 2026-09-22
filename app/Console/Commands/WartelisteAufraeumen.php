<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WaitlistStatus;
use App\Enums\WaitlistTrigger;
use App\Models\Organization;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Tenancy\TenantContext;
use App\Verfuegbarkeit\Slotvorschlag;
use App\Warteliste\Angebotsantwort;
use App\Warteliste\Vergabelauf;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Laesst abgelaufene Angebote ablaufen -- und startet die naechste Runde.
 *
 * **Die Staffelung braucht eine Uhr.** Ohne diesen Lauf bliebe ein Angebot
 * ewig offen, der Slot ewig gehalten und der naechste Kandidat ewig
 * ungefragt. Die Antwort der Interessentin ist der eine Weg aus einem
 * Angebot, der Ablauf der andere.
 */
final class WartelisteAufraeumen extends Command
{
    protected $signature = 'mrs:warteliste-aufraeumen';

    protected $description = 'Laesst abgelaufene Wartelistenangebote ablaufen und bietet den Slot dem naechsten an';

    public function handle(TenantContext $mandant, Angebotsantwort $antwort, Vergabelauf $vergabe): int
    {
        $jetzt = CarbonImmutable::now();
        $abgelaufen = 0;
        $neu = 0;

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($antwort, $vergabe, $jetzt, &$abgelaufen, &$neu): void {
                $offene = WaitlistOffer::query()
                    ->offen()
                    ->where('expires_at', '<=', $jetzt)
                    ->orderBy('expires_at')
                    ->get();

                foreach ($offene as $angebot) {
                    $slot = $antwort->slot($angebot);

                    $antwort->laufAb($angebot, $jetzt);
                    $abgelaufen++;

                    // Die naechste Runde -- bei einem wackeligen Termin
                    // nicht: dort ist der Slot ohnehin noch belegt.
                    if ($slot instanceof Slotvorschlag && $angebot->trigger !== WaitlistTrigger::NoResponse) {
                        $neu += $vergabe->biete($slot, $angebot->trigger, $jetzt) === null ? 0 : 1;
                    }
                }

                // Eintraege, deren Frist verstrichen ist.
                WaitlistEntry::query()
                    ->where('status', WaitlistStatus::Active->value)
                    ->where('expires_at', '<=', $jetzt)
                    ->update(['status' => WaitlistStatus::Expired->value]);
            });
        }

        $this->info("Fertig: {$abgelaufen} Angebote abgelaufen, {$neu} neue verschickt.");

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Wartelistenangebote laufen fuer alle Mandanten ab',
            fn (): iterable => Organization::query()->whereNull('suspended_at')->get(),
        );
    }
}
