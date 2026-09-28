<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Benachrichtigung\Versand\Plattformversand;
use App\Models\PlatformMailSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Throwable;

/**
 * Die Probemail des Plattformversands (WP-37, B23) -- **nur ueber den
 * hinterlegten Server**, ohne Rueckfall.
 *
 * Eine Probe, die bei einem Fehler ueber `.env` ginge, bestaende immer. Erst
 * wenn sie ueber genau diese Fassung der Zugangsdaten durchging, gilt der
 * Server; hat jemand inzwischen wieder etwas geaendert, zaehlt sie nicht.
 *
 * Verschluesselt in der Schlange: die Empfaengeradresse ist die einer Person.
 */
final class PlattformversandPruefen implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** Einmal. Falsche Zugangsdaten werden beim zweiten Versuch nicht richtig. */
    public int $tries = 1;

    public function __construct(
        private readonly int $fassung,
        private readonly string $empfaenger,
    ) {
        $this->onQueue('realtime');
        $this->afterCommit();
    }

    public function handle(Plattformversand $versand): void
    {
        $einstellung = PlatformMailSetting::query()->first();

        if (! $einstellung instanceof PlatformMailSetting || ! $einstellung->hatServer() || $einstellung->smtp_version !== $this->fassung) {
            return;
        }

        try {
            [$adresse, $name] = $versand->absender($einstellung, hinterlegt: true);

            $versand->hinterlegterMailer($einstellung)->raw(
                "Diese Nachricht bestätigt, dass der Versand der Plattform über den hinterlegten Mailserver funktioniert.\n\n"
                .'Ab jetzt gehen Anmeldecodes, Passwortlinks, Einladungen und Alarme über diesen Server.',
                function (Message $mail) use ($adresse, $name): void {
                    $mail->to($this->empfaenger)
                        ->from($adresse, $name)
                        ->subject('Probemail: Der Plattformversand ist eingerichtet');
                },
            );
        } catch (Throwable) {
            // **Ohne Klartext des Servers.** Eine SMTP-Meldung traegt
            // regelmaessig die Adresse des Empfaengers mit sich.
            $einstellung->meldeStoerung('smtp_failed');

            return;
        }

        // Nur die Fassung, ueber die die Probe lief -- aenderte jemand
        // inzwischen die Zugangsdaten, bleibt der Server ungeprueft.
        PlatformMailSetting::query()
            ->whereKey($einstellung->getKey())
            ->where('smtp_version', $this->fassung)
            ->update([
                'smtp_verified_version' => $this->fassung,
                'verified_at' => CarbonImmutable::now(),
                'failed_at' => null,
                'last_error' => null,
            ]);
    }
}
