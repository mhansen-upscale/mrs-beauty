<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\PlatformMailSetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Das Logo der Produktmails (WP-37) -- oeffentlich, weil ein Mailprogramm es
 * ohne Anmeldung laedt.
 *
 * **Die Fassung steht in der Adresse**: ein neues Logo bekommt eine neue
 * Adresse, und die alte darf ein Jahr im Zwischenspeicher liegen. Nur PNG
 * und JPEG, mit `nosniff` -- kein Browser deutet die Datei um.
 */
final class PlattformlogoController extends Controller
{
    public function __invoke(int $fassung): Response
    {
        $einstellung = PlatformMailSetting::query()->first();

        if (! $einstellung instanceof PlatformMailSetting
            || ! is_string($einstellung->logo_path) || $einstellung->logo_path === ''
            || $einstellung->logo_version !== $fassung
            || ! in_array($einstellung->logo_mime, (array) config('mrs.mail.logo_mimes'), true)) {
            abort(404);
        }

        $speicher = Storage::disk((string) config('mrs.attachments.disk'));

        if (! $speicher->exists($einstellung->logo_path)) {
            abort(404);
        }

        return response((string) $speicher->get($einstellung->logo_path), 200, [
            'Content-Type' => (string) $einstellung->logo_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Disposition' => 'inline',
        ]);
    }
}
