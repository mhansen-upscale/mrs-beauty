<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Datenschutz\Anhangspeicher;
use App\Enums\AttachmentContext;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Kanaele\Kanalfehler;
use App\Kanaele\WhatsApp\WhatsAppMedien;
use App\Models\ChannelConnection;
use App\Models\Message;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Holt die Datei zu einer WhatsApp-Nachricht und legt sie als Chat-Anhang ab
 * (offen seit WP-20a).
 *
 * **Ueber den Anhangspeicher aus WP-18**, nicht daneben: dort haengen die
 * Virenpruefung und das Pflicht-Ablaufdatum (Entscheidung C6). Ein ungefragt
 * zugesandtes Foto ist ein Gesundheitsdatum nach Artikel 9 DSGVO -- es soll
 * nicht dauerhaft liegen, und angezeigt wird es erst nach der Pruefung.
 *
 * **Nicht in der Zustellung** (Regel 4): die ist laengst quittiert, und ein
 * Media-Endpunkt, der gerade nicht antwortet, darf den Verlauf nicht aufhalten.
 */
final class MedienHolen implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly string $nachricht,
        private readonly string $organisation,
        private readonly string $kennung,
        private readonly ?string $dateiname = null,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(WhatsAppMedien $medien, Anhangspeicher $speicher): void
    {
        $organisation = Organization::query()->whereUuid($this->organisation)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runAs($organisation, function () use ($medien, $speicher): void {
            $nachricht = Message::query()->whereUuid($this->nachricht)->with('attachments')->first();

            // Schon da: ein zweiter Lauf legt keine zweite Datei ab.
            if (! $nachricht instanceof Message || $nachricht->attachments->isNotEmpty()) {
                return;
            }

            $verbindung = ChannelConnection::query()
                ->where('channel', ChannelType::WhatsApp->value)
                ->sendebereit()
                ->first();

            if (! $verbindung instanceof ChannelConnection) {
                return;
            }

            try {
                $datei = $medien->hole($verbindung, $this->kennung);
            } catch (Kanalfehler $fehler) {
                if ($fehler->einordnung->zustand instanceof ConnectionStatus) {
                    $verbindung->meldeAusfall($fehler->einordnung->zustand, $fehler->einordnung->kurzgrund);
                }

                if ($fehler->einordnung->wiederholen) {
                    $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
                }

                return;
            }

            if ($datei === null) {
                // Kein Fehler: zu gross, abgelaufen oder nicht bei Meta. Der
                // Verlauf nennt den Medientyp trotzdem.
                Log::info('WhatsApp-Datei nicht geholt', ['kurzgrund' => 'unavailable']);

                return;
            }

            $speicher->lege($nachricht, $datei['inhalt'], $this->name($datei['mime']), AttachmentContext::Chat);
        });
    }

    /** Der Name des Absenders -- oder einer, der nichts ueber die Person sagt. */
    private function name(string $mime): string
    {
        if (is_string($this->dateiname) && trim($this->dateiname) !== '') {
            return mb_substr(trim($this->dateiname), 0, 200);
        }

        $endung = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/mpeg' => 'mp3',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        return 'whatsapp.'.$endung;
    }
}
