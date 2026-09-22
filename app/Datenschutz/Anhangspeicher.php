<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Enums\AttachmentContext;
use App\Models\Attachment;
use App\Models\User;
use App\Support\FieldCipher;
use App\Tenancy\KeyRing;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Anhaenge ablegen, lesen, entfernen -- verschluesselt.
 *
 * **Die Datei liegt ausserhalb der Datenbank, aber unter demselben
 * Schluessel** (Entscheidung A6): dem der Organisation. Ein Speicherabzug
 * ohne Schluesselsatz ist damit wertlos, und ein Mandant kann die Dateien
 * eines anderen nicht lesen, selbst wenn er an den Pfad kaeme.
 *
 * **Loeschen heisst beides.** Der Datensatz allein zu entfernen liesse die
 * Datei liegen -- bei ungefragt zugesandten Fotos ist das der Unterschied
 * zwischen geloescht und nur unsichtbar.
 */
final class Anhangspeicher
{
    public function __construct(private readonly Virenpruefung $viren) {}

    /**
     * Legt einen Anhang ab.
     *
     * Ein Chat-Anhang **muss** ein Ablaufdatum haben (Entscheidung C6). Die
     * Datenbank besteht ebenfalls darauf; die Pruefung hier gibt nur die
     * bessere Fehlermeldung.
     */
    public function lege(
        Model $traeger,
        string $inhalt,
        string $dateiname,
        AttachmentContext $kontext,
        ?CarbonImmutable $laeuftAb = null,
        ?User $wer = null,
    ): Attachment {
        $laeuftAb ??= $kontext->brauchtAblauf()
            ? CarbonImmutable::now()->addDays((int) config('mrs.retention.chat_attachment_days', 90))
            : null;

        if ($kontext->brauchtAblauf() && $laeuftAb === null) {
            throw Anhangabgelehnt::ohneAblaufdatum();
        }

        $pfad = $this->pfad();

        Storage::disk($this->platte())->put(
            $pfad,
            FieldCipher::encrypt($inhalt, $this->schluessel()),
        );

        $anhang = new Attachment;
        $anhang->attachable_type = $traeger::class;
        $anhang->attachable_id = $traeger->getKey();
        $anhang->uploaded_by_user_id = $wer?->getKey();
        $anhang->context = $kontext;
        $anhang->original_name = $dateiname;
        $anhang->path = $pfad;
        $anhang->mime = $this->mime($inhalt);
        $anhang->size_bytes = strlen($inhalt);
        $anhang->checksum = hash('sha256', $inhalt, true);
        $anhang->expires_at = $laeuftAb;
        $anhang->save();

        $this->viren->pruefe($anhang, $inhalt);

        return $anhang;
    }

    /**
     * Der Inhalt -- nur, wenn er geprueft und unbeanstandet ist.
     *
     * Wer eine Datei ausliefert, bevor sie geprueft ist, reicht weiter, was
     * jemand ungefragt geschickt hat.
     */
    public function inhalt(Attachment $anhang): string
    {
        if (! $anhang->istFreigegeben()) {
            throw Anhangabgelehnt::nichtFreigegeben();
        }

        return $this->rohinhalt($anhang);
    }

    /** Der Inhalt ohne Freigabepruefung -- fuer die Pruefung selbst. */
    public function rohinhalt(Attachment $anhang): string
    {
        $nutzlast = Storage::disk($this->platte())->get($anhang->path);

        if (! is_string($nutzlast)) {
            throw Anhangabgelehnt::nichtImSpeicher($anhang->path);
        }

        return FieldCipher::decrypt($nutzlast, $this->schluessel($anhang->organization_id));
    }

    /** Datensatz **und** Datei. */
    public function entferne(Attachment $anhang): void
    {
        Storage::disk($this->platte())->delete($anhang->path);

        $anhang->delete();
    }

    private function pfad(): string
    {
        $mandant = app(TenantContext::class)->requireId(Attachment::class);

        // Nach Mandant getrennt, damit ein versehentlich zu weit gefasster
        // Loeschlauf nicht ueber die Grenze greift.
        return 'anhaenge/'.bin2hex($mandant).'/'.Str::uuid()->toString();
    }

    private function platte(): string
    {
        return (string) config('mrs.attachments.disk', 'local');
    }

    private function schluessel(?string $organisation = null): string
    {
        $organisation ??= app(TenantContext::class)->requireId(Attachment::class);

        return app(KeyRing::class)->for($organisation)->dataEncryptionKey;
    }

    /**
     * Der Typ aus dem Inhalt, nicht aus dem Dateinamen.
     *
     * Eine Endung ist eine Behauptung des Absenders. Bei ungefragt
     * zugesandten Dateien ist sie die unzuverlaessigste Angabe ueberhaupt.
     */
    private function mime(string $inhalt): string
    {
        $erkannt = (new \finfo(FILEINFO_MIME_TYPE))->buffer($inhalt);

        return is_string($erkannt) && $erkannt !== '' ? $erkannt : 'application/octet-stream';
    }
}
