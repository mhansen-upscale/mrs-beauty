<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Enums\MessageStatus;
use App\Kanaele\Kanalfehler;
use App\Kanaele\KanalNichtAngebunden;
use App\Kanaele\Kanalversender;
use App\Kanaele\Nachrichtenversand;
use App\Models\ChannelConnection;
use App\Models\Message;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Schickt eine eingereihte Nachricht.
 *
 * **Nicht jeder Fehler ist eine Wiederholung.** Die Einordnung steht in
 * Fehlereinordnung, nach der Tabelle aus docs/integrationen/meta.md: ein Rate Limit
 * wird wiederholt, ein ungueltiges Token nicht. Wer alles wiederholt,
 * verdeckt, dass jemand die Verbindung erneuern muss -- und die Nachricht
 * geht trotzdem nie raus.
 *
 * Queue `realtime`: eine Antwort an einen Menschen, der gerade schreibt, hat
 * Vorrang vor einem Kalenderabgleich (WP-33).
 */
final class NachrichtSenden implements ShouldQueue
{
    use Queueable;

    /** Rate Limits werden zurueckweichend wiederholt, nicht sofort. */
    public int $tries = 5;

    /**
     * Exponentiell: eine Minute, vier, fuenfzehn, eine Stunde.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 240, 900, 3600];
    }

    public function __construct(
        private readonly string $nachricht,
        private readonly string $organisation,
    ) {
        $this->onQueue('realtime');

        // Erst nach dem Commit: der Auftrag entsteht in der Transaktion der
        // Aufrufstelle, und ein schnellerer Arbeiter faende die Zeile nicht.
        $this->afterCommit();
    }

    public function handle(Kanalversender $versender, Nachrichtenversand $versand): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($organisation, function () use ($versender, $versand): void {
            $nachricht = Message::query()
                ->whereUuid($this->nachricht)
                ->with('conversation')
                ->first();

            if (! $nachricht instanceof Message || $nachricht->status !== MessageStatus::Queued) {
                return;
            }

            $verbindung = ChannelConnection::query()
                ->where('channel', $nachricht->channel->value)
                ->sendebereit()
                ->first();

            if (! $verbindung instanceof ChannelConnection) {
                // Keine sendebereite Verbindung: kein Grund zu wiederholen,
                // bis jemand sie wieder herstellt.
                $versand->vermerkeFehlschlag($nachricht, 'no_connection');

                return;
            }

            try {
                $ergebnis = $versender->fuer($nachricht->channel)->sende($verbindung, $nachricht);
            } catch (KanalNichtAngebunden) {
                // Ehrlicher Endzustand nach WP-19: die Strecke steht, der
                // Kanal kommt in WP-20.
                $versand->vermerkeFehlschlag($nachricht, 'channel_missing');

                return;
            } catch (Kanalfehler $fehler) {
                $this->behandle($fehler, $verbindung, $nachricht, $versand);

                return;
            }

            $versand->vermerkeErfolg($nachricht, $ergebnis);
        });
    }

    /**
     * Die Fehlertabelle, angewandt.
     *
     * Der Zustand der Verbindung wird gesetzt, **bevor** entschieden wird, ob
     * wiederholt wird: auch ein Versuch, der noch einmal laeuft, soll den
     * Hinweis im Produkt hinterlassen.
     */
    private function behandle(
        Kanalfehler $fehler,
        ChannelConnection $verbindung,
        Message $nachricht,
        Nachrichtenversand $versand,
    ): void {
        $einordnung = $fehler->einordnung;

        if ($einordnung->zustand instanceof ConnectionStatus) {
            $verbindung->meldeAusfall($einordnung->zustand, $einordnung->kurzgrund);
        }

        if ($einordnung->wiederholen) {
            // Die Nachricht bleibt eingereiht; die Queue versucht es erneut,
            // mit wachsendem Abstand.
            $this->release($this->backoff()[min($this->attempts() - 1, 3)]);

            return;
        }

        $versand->vermerkeFehlschlag($nachricht, $einordnung->kurzgrund);
    }
}
