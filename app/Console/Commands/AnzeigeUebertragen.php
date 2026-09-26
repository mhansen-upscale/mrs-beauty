<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Bildformat;
use App\Enums\SyncState;
use App\Jobs\AnzeigeUebertragen as Auftrag;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\Organization;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Eine haengende Anzeige ansehen und von Hand zu Meta tragen.
 *
 * **Fuer den Fall, den die Oberflaeche nicht erklaert.** Steht eine Anzeige
 * auf "wird uebertragen" und es kommt nichts, hat der Auftrag weder einen
 * Grund vermerkt noch aufgegeben -- er ist nie angelaufen oder verloren
 * gegangen. So am 26.09.2026 auf Staging, eine Viertelstunde lang. Die
 * Laravel-Cloud-Konsole nimmt nur einzelne Kommandos; mehrzeiliges tinker
 * geht dort nicht.
 *
 * Uebertragen wird synchron, am Worker vorbei: ein Fehler steht sofort hier
 * und nicht erst im Protokoll. Eine Dublette entsteht dabei nicht --
 * `Anzeigenschaltung::uebertrage()` sucht zuerst per Merkmal bei Meta.
 */
final class AnzeigeUebertragen extends Command
{
    protected $signature = 'mrs:anzeige-uebertragen
        {anzeige : UUID der Anzeige}
        {--nur-zeigen : Nur den Zustand ausgeben, nichts senden}';

    protected $description = 'Zeigt, woran eine Anzeige haengt, und uebertraegt sie von Hand zu Meta';

    public function handle(TenantContext $mandant, FailedJobProviderInterface $gescheitert): int
    {
        $kennung = Str::lower((string) $this->argument('anzeige'));

        if (! Uuid::isCanonical($kennung)) {
            $this->error('Keine gueltige UUID: '.$kennung);

            return self::FAILURE;
        }

        // **Nur die Zugehoerigkeit wird mandantenuebergreifend gelesen**, als
        // Rohwert und ohne Modell: der Name der Anzeige ist mit dem
        // Schluessel ihrer Praxis verschluesselt.
        $organisationId = $mandant->acrossTenants(
            'Anzeige von Hand uebertragen: '.$kennung,
            fn (): mixed => Ad::query()->whereUuid($kennung)->value('organization_id'),
        );

        $organisation = is_string($organisationId)
            ? Organization::query()->whereKey($organisationId)->first()
            : null;

        if (! $organisation instanceof Organization) {
            $this->error('Keine Anzeige mit dieser Kennung.');

            return self::FAILURE;
        }

        // **Ab hier nie innerhalb von acrossTenants().** runAs() setzt den
        // Mandanten, hebt einen ausgesetzten Scope aber nicht wieder auf. Der
        // Auftrag faende sonst mit AdAccount::first() das Werbekonto
        // irgendeiner Praxis (Regel 1).
        return $mandant->runAs(
            $organisation,
            fn (): int => $this->fuerPraxis($organisation, $kennung, $gescheitert),
        );
    }

    private function fuerPraxis(Organization $organisation, string $kennung, FailedJobProviderInterface $gescheitert): int
    {
        $anzeige = Ad::query()->whereUuid($kennung)->first();

        if (! $anzeige instanceof Ad) {
            $this->error('Keine Anzeige mit dieser Kennung.');

            return self::FAILURE;
        }

        $auftrag = new Auftrag((string) $organisation->uuid, $kennung);

        $this->zeigeZustand($anzeige);
        $this->zeigeUmfeld($auftrag, $kennung, $gescheitert);

        if ((bool) $this->option('nur-zeigen')) {
            return self::SUCCESS;
        }

        if ($anzeige->sync_state === SyncState::Synced) {
            $this->info('Die Anzeige ist bereits uebertragen. Nichts zu tun.');

            return self::SUCCESS;
        }

        // Wie anzeigen.erneut: der alte Grund stuende sonst weiter da -- und
        // Abbruchvermerk zoege ihn einem neuen Fehlschlag vor.
        $anzeige->sync_state = SyncState::Pending;
        $anzeige->sync_error = null;
        $anzeige->save();

        $this->newLine();
        $this->line('Uebertrage ...');

        // dispatchSync prueft die Sperre aus ShouldBeUnique nicht und gibt
        // sie danach frei -- auch eine, die ein verlorener Auftrag
        // zuruecklassen hat.
        try {
            dispatch_sync($auftrag);
        } catch (Throwable $fehler) {
            $this->error(class_basename($fehler).': '.$fehler->getMessage());
        }

        $frisch = $anzeige->fresh() ?? $anzeige;

        $this->newLine();
        $this->zeigeZustand($frisch);

        if ($frisch->sync_state === SyncState::Synced) {
            $this->info('Uebertragen.');

            return self::SUCCESS;
        }

        if ($frisch->sync_state === SyncState::Pending && $frisch->sync_error === null) {
            // Der Auftrag kehrt ohne Vermerk zurueck, wenn kein Werbekonto
            // aktiv ist -- die Absicht bleibt, der Hinweis haengt am Konto.
            $this->warn('Nichts uebertragen. Ist das Werbekonto verbunden? Siehe oben.');
        }

        return self::FAILURE;
    }

    /**
     * Wie weit der Auftrag kam.
     *
     * Die drei Schritte bauen aufeinander auf -- Bilder, Creative, Anzeige.
     * Welcher gesetzt ist, sagt, wo er stehen blieb.
     */
    private function zeigeZustand(Ad $anzeige): void
    {
        $beiMeta = $anzeige->external_id !== '' && ! str_starts_with($anzeige->external_id, 'lokal-');

        $this->zeile('Zustand', $anzeige->sync_state->value);
        $this->zeile('Grund', $anzeige->sync_error ?? '-');
        $this->zeile('Formate hochgeladen', count($anzeige->image_hashes ?? []).' von '.count(Bildformat::cases()));
        $this->zeile('Creative angelegt', $anzeige->creative_external_id !== null ? 'ja' : 'nein');
        $this->zeile('Anzeige bei Meta', $beiMeta ? 'ja ('.$anzeige->external_id.')' : 'nein');
        $this->zeile('Angelegt (UTC)', (string) $anzeige->created_at?->toDateTimeString());
        $this->zeile('Geaendert (UTC)', (string) $anzeige->updated_at?->toDateTimeString());
    }

    /**
     * Was ausserhalb der Anzeige liegt: Werbekonto, Warteschlange, der
     * letzte Abbruch.
     */
    private function zeigeUmfeld(Auftrag $auftrag, string $kennung, FailedJobProviderInterface $gescheitert): void
    {
        $konto = AdAccount::query()->whereNull('disconnected_at')->first();

        $this->zeile('Werbekonto', $konto instanceof AdAccount
            ? $konto->status->value.($konto->last_error !== null ? ' - '.$konto->last_error : '')
            : 'keines verbunden');

        $warteschlange = $auftrag->queue ?? 'default';
        $wartend = Queue::size($warteschlange);

        $this->zeile('Warteschlange', $warteschlange.': '.$wartend.' wartend');

        if ($wartend > 0) {
            $this->warn("Auf '{$warteschlange}' liegt etwas. Holt dort ein Worker ab? Siehe docs/betrieb.md.");
        }

        $abbruch = $this->letzterAbbruch($kennung, $gescheitert);

        $this->zeile('Letzter Abbruch', $abbruch ?? '-');
    }

    /**
     * Der juengste Eintrag in failed_jobs zu genau dieser Anzeige.
     *
     * Nur die erste Zeile der Ausnahme: Klasse, Meldung, Stelle. Den Rest
     * zeigt `queue:failed`.
     */
    private function letzterAbbruch(string $kennung, FailedJobProviderInterface $gescheitert): ?string
    {
        foreach ($gescheitert->all() as $eintrag) {
            $nutzlast = data_get($eintrag, 'payload');

            if (! is_string($nutzlast)
                || ! str_contains($nutzlast, 'AnzeigeUebertragen')
                || ! str_contains(Str::lower($nutzlast), $kennung)) {
                continue;
            }

            $ausnahme = data_get($eintrag, 'exception');
            $zeitpunkt = data_get($eintrag, 'failed_at');

            return (is_string($zeitpunkt) ? $zeitpunkt.' - ' : '')
                .(is_string($ausnahme) ? Str::before($ausnahme, "\n") : 'ohne Angabe');
        }

        return null;
    }

    private function zeile(string $feld, string $wert): void
    {
        $this->line(sprintf('%-20s %s', $feld, $wert));
    }
}
