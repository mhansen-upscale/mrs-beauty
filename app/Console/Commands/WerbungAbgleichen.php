<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdAccount;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Abgleichbilanz;
use App\Werbung\Strukturabgleich;
use App\Werbung\Werbefehler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Holt die Kampagnenstruktur aller verbundenen Werbekonten.
 *
 * Taeglich. Eine Kampagne, die jemand bei Meta pausiert, muss im Produkt
 * pausiert aussehen -- sonst erklaert sich eine ausbleibende Anfrage nicht.
 *
 * **Ein Fehler bei einer Praxis haelt die uebrigen nicht an.** Ein Konto mit
 * abgelaufenem Token wuerde einen gemeinsamen Lauf sonst jeden Tag an
 * derselben Stelle abbrechen.
 */
final class WerbungAbgleichen extends Command
{
    protected $signature = 'mrs:werbung-abgleichen
        {--organisation= : Nur diese Organisation, als UUID}';

    protected $description = 'Gleicht Kampagnen, Anzeigengruppen und Anzeigen mit Meta ab';

    public function handle(TenantContext $mandant, Strukturabgleich $abgleich): int
    {
        $jetzt = CarbonImmutable::now();
        $gesamt = new Abgleichbilanz;
        $gestoert = 0;

        foreach ($this->organisationen($mandant) as $organisation) {
            $mandant->runAs($organisation, function () use ($organisation, $abgleich, $jetzt, &$gesamt, &$gestoert): void {
                $konto = AdAccount::query()->whereNull('disconnected_at')->first();

                if (! $konto instanceof AdAccount || ! $konto->istVerbunden()) {
                    return;
                }

                $this->warnung($konto, $organisation, $jetzt);

                try {
                    $bilanz = $abgleich->gleicheAb($konto, $jetzt);
                } catch (Werbefehler $fehler) {
                    // Der Zustand gehoert ans Werbekonto, damit die Praxis ihn
                    // im Produkt sieht (Regel 4).
                    if ($fehler->einordnung->zustand !== null) {
                        $konto->meldeAusfall($fehler->einordnung->zustand, $fehler->einordnung->kurzgrund);
                    }

                    $gestoert++;
                    $this->warn($organisation->name.': '.$fehler->einordnung->kurzgrund);

                    return;
                }

                $gesamt = $gesamt->plus($bilanz->angelegt, $bilanz->geaendert, $bilanz->verschwunden);
            });
        }

        $this->info(
            "Fertig: {$gesamt->angelegt} neu, {$gesamt->geaendert} geaendert, "
            ."{$gesamt->verschwunden} verschwunden, {$gestoert} gestoert."
        );

        return self::SUCCESS;
    }

    /**
     * Ablaufwarnung fuer das Token.
     *
     * `docs/integrationen/meta.md` verlangt sie ausdruecklich. Ein Token, das
     * am Ablauftag auffaellt, faellt zu spaet auf: bis eine Praxis den
     * Business Manager wiedergefunden hat, vergehen Tage.
     */
    private function warnung(AdAccount $konto, Organization $organisation, CarbonImmutable $jetzt): void
    {
        $ablauf = $konto->token_expires_at;
        $vorlauf = (int) config('mrs.ads.token_warning_days');

        if ($ablauf === null || $ablauf->greaterThan($jetzt->addDays($vorlauf))) {
            return;
        }

        $this->warn($organisation->name.': Zugang laeuft am '.$ablauf->format('d.m.Y').' ab.');
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Werbekonten werden fuer alle Mandanten abgeglichen',
            function (): iterable {
                $abfrage = Organization::query()->whereNull('suspended_at');

                if (is_string($this->option('organisation'))) {
                    $abfrage->whereUuid((string) $this->option('organisation'));
                }

                return $abfrage->get();
            }
        );
    }
}
