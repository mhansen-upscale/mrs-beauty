<?php

declare(strict_types=1);

namespace App\Http\Controllers\Datenschutz;

use App\Audit\AuditLogger;
use App\Audit\ImpersonationContext;
use App\Datenschutz\Anhangspeicher;
use App\Enums\Ability;
use App\Enums\AttachmentContext;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\BrandReference;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Liefert einen Anhang aus -- offen seit WP-21, "samt der Frage, wer ihn
 * sehen darf".
 *
 * **Woran er haengt, entscheidet, wer ihn sieht.** Ein Chat-Anhang gehoert
 * zum Posteingang, Referenzmaterial zum Brand Guide, ein Dokument zum
 * Kontakt. Was an nichts davon haengt, gibt es hier nicht.
 *
 * **Wer hineinsehen darf, heisst noch nicht, was hinausgeht:**
 *
 * - **Nur Geprueftes** (WP-18, WP-33). Was niemand geprueft hat, wird nicht
 *   weitergereicht -- auch Referenzmaterial nicht (WP-29 haelt das fest).
 *   Die eine Ausnahme ist das Logo mit seiner eigenen Route (WP-07).
 * - **Nichts waehrend einer maskierten Impersonation** (C4). Ein Foto laesst
 *   sich nicht maskieren wie ein Name.
 *
 * Anzeigenbilder haben ihre eigene Route (WP-31) und gehoeren nicht hierher.
 *
 * **Angezeigt werden nur Bilder**, und die in einer Sandbox. Alles andere
 * wird heruntergeladen, als Bytes: eine HTML-Datei aus dem Chat, im Browser
 * geoeffnet, liefe unter unserer Adresse.
 */
final class AnhangController extends Controller
{
    public function __invoke(
        Attachment $attachment,
        Anhangspeicher $speicher,
        AuditLogger $protokoll,
        ImpersonationContext $impersonation,
    ): StreamedResponse {
        abort_if($impersonation->masks(), 403, 'Während einer maskierten Sitzung werden keine Anhänge geöffnet.');

        Gate::authorize($this->berechtigung($attachment)->value);

        abort_unless($attachment->istFreigegeben(), 404);

        $inhalt = $speicher->rohinhalt($attachment);

        // Gesundheitsdaten nach Artikel 9: wer ein Foto aus dem Chat oeffnet,
        // steht im Protokoll -- nicht der Inhalt, aber der Zugriff.
        if ($attachment->context === AttachmentContext::Chat) {
            $protokoll->record(AuditEvent::AttachmentOpened, gegenstand: $attachment);
        }

        $bild = $attachment->istBild();

        return response()->stream(
            function () use ($inhalt): void {
                echo $inhalt;
            },
            200,
            [
                'Content-Type' => $bild ? $attachment->mime : 'application/octet-stream',
                'Content-Length' => (string) strlen($inhalt),
                'Content-Disposition' => $this->disposition($attachment, $bild),

                // Ohne das raet der Browser den Typ selbst -- und raet bei
                // einer praeparierten Datei falsch.
                'X-Content-Type-Options' => 'nosniff',

                // Selbst ein Bild bekommt keine Rechte: kein Skript, keine
                // Formulare, kein Nachladen.
                'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",

                // Weder im Browser noch in einem Zwischenspeicher dazwischen.
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    private function berechtigung(Attachment $anhang): Ability
    {
        return match ($anhang->attachable_type) {
            Message::class => Ability::ViewInbox,
            BrandReference::class => Ability::ManageBrandGuide,
            Contact::class => Ability::ManageContacts,
            default => abort(404),
        };
    }

    /**
     * Der Dateiname, sicher kodiert. Der Name stammt vom Absender und ist
     * eine Behauptung -- er darf keinen Kopf der Antwort aufbrechen.
     */
    private function disposition(Attachment $anhang, bool $bild): string
    {
        $name = trim((string) $anhang->original_name);
        $name = $name === '' ? 'anhang' : $name;

        $ersatz = Str::ascii($name);
        $ersatz = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $ersatz);

        return HeaderUtils::makeDisposition(
            $bild ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            $ersatz === '' ? 'anhang' : $ersatz,
        );
    }
}
