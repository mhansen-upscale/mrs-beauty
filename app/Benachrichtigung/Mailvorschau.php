<?php

declare(strict_types=1);

namespace App\Benachrichtigung;

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Eine Mail als Vorschau: Betreff, HTML und Textteil -- **verschickt nichts**
 * (WP-36 AK 17, WP-37 AK 18).
 *
 * Gerendert wie beim Versand, mit Theme und Farbe; angezeigt wird das HTML in
 * einem iframe mit `sandbox=""` (docs/konventionen.md) -- ohne Skript, ohne
 * Formular, ohne Zugriff auf die Seite drumherum.
 */
final class Mailvorschau
{
    /**
     * @return array{betreff: string, html: string, text: string}
     */
    public static function aus(MailMessage $nachricht): array
    {
        $markdown = app(Markdown::class)->theme($nachricht->theme ?? 'default');

        return [
            'betreff' => (string) $nachricht->subject,
            'html' => (string) $nachricht->render(),
            'text' => trim((string) $markdown->renderText((string) $nachricht->markdown, $nachricht->data())),
        ];
    }
}
