<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Kanaele\Eingangsverarbeitung;
use App\Kanaele\Rohereignisse;
use App\Models\ChannelRawEvent;
use App\Models\Organization;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spielt liegengebliebene Rohereignisse erneut ein (WP-19, Nachtrag 28.09.2026).
 *
 * **Fuer den Auftrag, der nie lief.** Ein gescheiterter steht in failed_jobs
 * und laesst sich mit queue:retry wiederholen. Einer, der nie angelaufen ist
 * -- so in WP-33, als lokal kein Worker `realtime` abholte --, steht nirgends.
 * Nach 14 Tagen raeumt die Aufbewahrung das Rohereignis weg, und mit ihm die
 * Nachricht.
 *
 * Eingespielt wird synchron, am Worker vorbei: ein Fehler steht sofort hier,
 * und der Befehl hilft gerade dann, wenn der Worker fehlt. Die Folgeauftraege
 * -- Einordnung, Medien -- gehen weiter ueber die Queue.
 *
 * **Ausgegeben wird nie die Nutzlast** und nie die Meldung einer Ausnahme:
 * beide tragen bei Nachrichtenkanaelen regelmaessig Inhalte (Regel 3, C5).
 * Gesperrte Praxen werden eingespielt wie im Auftrag RohereignisVerarbeiten,
 * der die Sperre ebenfalls nicht prueft.
 */
final class RohereignisseEinspielen extends Command
{
    protected $signature = 'mrs:rohereignisse-einspielen
        {--organisation= : Nur diese Organisation, als UUID}
        {--kanal= : Nur dieser Kanal, etwa whatsapp oder email}
        {--hoechstens=100 : Hoechstens so viele je Organisation}
        {--nur-zeigen : Nur zeigen, was liegt, nichts einspielen}';

    protected $description = 'Spielt liegengebliebene Rohereignisse erneut ein (Vorschau mit --nur-zeigen)';

    public function handle(TenantContext $mandant, Rohereignisse $rohereignisse, Eingangsverarbeitung $verarbeitung): int
    {
        $organisation = $this->option('organisation');

        if (is_string($organisation) && ! Uuid::isCanonical(Str::lower($organisation))) {
            $this->error('Keine gueltige UUID: '.$organisation);

            return self::FAILURE;
        }

        $kanal = null;

        if (is_string($this->option('kanal'))) {
            $kanal = ChannelType::tryFrom(Str::lower((string) $this->option('kanal')));

            if (! $kanal instanceof ChannelType) {
                $this->error(sprintf(
                    'Unbekannter Kanal: %s. Moeglich: %s',
                    (string) $this->option('kanal'),
                    implode(', ', array_map(fn (ChannelType $fall): string => $fall->value, ChannelType::cases())),
                ));

                return self::FAILURE;
            }
        }

        $hoechstens = max(1, (int) $this->option('hoechstens'));
        $nurZeigen = (bool) $this->option('nur-zeigen');
        $jetzt = CarbonImmutable::now();
        $stand = ['gesehen' => 0, 'eingespielt' => 0, 'neu' => 0, 'liegenGeblieben' => 0];

        foreach ($this->organisationen($mandant) as $praxis) {
            // **Nie innerhalb von acrossTenants()**: runAs() setzt den
            // Mandanten, hebt einen ausgesetzten Scope aber nicht wieder auf
            // (siehe AnzeigeUebertragen). Die Nachricht landete sonst bei
            // irgendeiner Praxis (Regel 1).
            $mandant->runAs($praxis, function () use ($praxis, $rohereignisse, $verarbeitung, $kanal, $hoechstens, $jetzt, $nurZeigen, &$stand): void {
                $ereignisse = $rohereignisse->offene($kanal, $hoechstens, $jetzt);

                if ($ereignisse->isEmpty()) {
                    return;
                }

                $this->line($praxis->name.' ('.$praxis->uuid.')');

                foreach ($ereignisse as $ereignis) {
                    $stand['gesehen']++;

                    if ($nurZeigen) {
                        $this->zeige($ereignis);

                        continue;
                    }

                    $ergebnis = $this->spieleEin($ereignis, $rohereignisse, $verarbeitung);

                    if ($ergebnis === null) {
                        $stand['liegenGeblieben']++;

                        continue;
                    }

                    $stand['eingespielt']++;
                    $stand['neu'] += $ergebnis;
                }
            });
        }

        if ($stand['gesehen'] === 0) {
            $this->info('Nichts liegengeblieben.');

            return self::SUCCESS;
        }

        if ($nurZeigen) {
            $this->info("Vorschau: {$stand['gesehen']} liegengebliebene Rohereignisse. Ohne --nur-zeigen einspielen.");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Eingespielt: %d, neue Nachrichten: %d, weiterhin liegengeblieben: %d.',
            $stand['eingespielt'],
            $stand['neu'],
            $stand['liegenGeblieben'],
        ));

        if ($stand['neu'] > 0) {
            $this->line('Einordnung und Medien laufen ueber die Queue. Holt dort ein Worker ab? Siehe docs/betrieb.md.');
        }

        return $stand['liegenGeblieben'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Ein Ereignis einspielen.
     *
     * @return int|null Neue Nachrichten, oder null, wenn es liegen bleibt
     */
    private function spieleEin(ChannelRawEvent $ereignis, Rohereignisse $rohereignisse, Eingangsverarbeitung $verarbeitung): ?int
    {
        try {
            $neu = $verarbeitung->verarbeite($ereignis);
        } catch (Throwable $fehler) {
            $rohereignisse->vermerkeFehlschlag($ereignis, 'replay_failed');

            // Die Klasse, nie die Meldung: die eines Anbieters traegt
            // regelmaessig den Inhalt der Nachricht mit sich.
            $this->zeile($ereignis, 'gescheitert: '.class_basename($fehler));

            return null;
        }

        // Ohne Leser vermerkt die Verarbeitung selbst den Grund und laesst
        // das Ereignis offen -- das ist kein Erfolg.
        if ($ereignis->processed_at === null) {
            $this->zeile($ereignis, 'liegt weiter: '.($ereignis->failure ?? 'ohne Grund'));

            return null;
        }

        $this->zeile($ereignis, 'eingespielt, '.$neu.' neu');

        return $neu;
    }

    private function zeige(ChannelRawEvent $ereignis): void
    {
        $this->zeile($ereignis, sprintf(
            '%s UTC  Versuche %d  %s',
            (string) $ereignis->created_at?->toDateTimeString(),
            $ereignis->attempts,
            $ereignis->failure ?? '-',
        ));
    }

    private function zeile(ChannelRawEvent $ereignis, string $text): void
    {
        $this->line(sprintf('  %s  %-10s %s', (string) $ereignis->uuid, $ereignis->channel->value, $text));
    }

    /**
     * @return iterable<int, Organization>
     */
    private function organisationen(TenantContext $mandant): iterable
    {
        return $mandant->acrossTenants(
            'Liegengebliebene Rohereignisse werden fuer alle Mandanten eingespielt',
            function (): iterable {
                $abfrage = Organization::query()->orderBy('created_at');

                if (is_string($this->option('organisation'))) {
                    $abfrage->whereUuid(Str::lower((string) $this->option('organisation')));
                }

                return $abfrage->get();
            },
        );
    }
}
