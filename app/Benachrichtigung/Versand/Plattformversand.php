<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Versand;

use App\Models\PlatformMailSetting;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Der Versand der Plattform: der hinterlegte Server, sonst `.env` (B23).
 *
 * **Je Versand gelesen**, nicht beim Start: eine Aenderung im Backoffice
 * wirkt ab der naechsten Mail, ohne dass jemand die Arbeiter neu startet.
 * Eine Abfrage je Mail ist der Preis, und er ist klein.
 *
 * **Gespeichert ist nicht geprueft.** Der hinterlegte Server gilt erst, wenn
 * die Probemail ueber genau diese Fassung der Zugangsdaten ging
 * (PlatformMailSetting::serverGilt()). Wer sich beim Hostnamen vertippt,
 * sperrt sonst jeden aus, der auf einen Anmeldecode wartet.
 */
final class Plattformversand
{
    /** Der Transport des hinterlegten Servers -- Tests tauschen ihn. */
    public const TRANSPORT = 'plattform_smtp';

    public function einstellung(): PlatformMailSetting
    {
        return PlatformMailSetting::aktuell();
    }

    /** Der hinterlegte Server -- ohne Pruefung, ob er gilt (fuer die Probemail). */
    public function hinterlegterMailer(PlatformMailSetting $einstellung): Mailer
    {
        return Mail::build(Smtpzugang::ausEinstellung($einstellung)->konfiguration(self::TRANSPORT));
    }

    /** Der Rueckfall: der Mailer aus `.env`. */
    public function rueckfall(): Mailer
    {
        return Mail::mailer();
    }

    /**
     * **Der Absender gehoert zum Server.** Mit dem hinterlegten die
     * hinterlegte Adresse, mit `.env` die aus MAIL_FROM_* -- eine Adresse,
     * die der Server nicht senden darf, scheitert an SPF.
     *
     * @return array{0: string, 1: string|null}
     */
    public function absender(PlatformMailSetting $einstellung, bool $hinterlegt): array
    {
        if ($hinterlegt && is_string($einstellung->from_address) && $einstellung->from_address !== '') {
            return [$einstellung->from_address, $einstellung->from_name ?: (string) config('mail.from.name')];
        }

        return [(string) config('mail.from.address'), (string) config('mail.from.name')];
    }

    public function antwortAn(PlatformMailSetting $einstellung): ?string
    {
        return is_string($einstellung->reply_to_address) && $einstellung->reply_to_address !== ''
            ? $einstellung->reply_to_address
            : null;
    }
}
