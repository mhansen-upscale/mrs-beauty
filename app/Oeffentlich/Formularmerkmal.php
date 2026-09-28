<?php

declare(strict_types=1);

namespace App\Oeffentlich;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Ein Merkmal im Demo-Formular: wann die Seite geladen wurde (WP-38).
 *
 * **Gegen Formular-Bots, ohne Dritte.** Kein reCAPTCHA -- das hiesse ein
 * Skript von Google auf einer Seite, die sonst niemanden anspricht. Stattdessen
 * drei Zaeune: der Honigtopf im Formular, die Drosselung an der Route und
 * dieses Merkmal. Wer schneller absendet, als ein Mensch fuenf Felder
 * ausfuellt, oder ein Merkmal von gestern wiederverwendet, ist keiner.
 *
 * **Zustandslos.** Der Zeitstempel steht verschluesselt im Merkmal selbst,
 * nicht in der Sitzung: ein Fehler im Formular und ein Zurueck laesst es
 * gueltig, und eine Sitzung, die es nicht gibt, kann es nicht verlieren.
 */
final class Formularmerkmal
{
    private const PRAEFIX = 'demoanfrage:';

    public function erzeuge(?CarbonImmutable $geladen = null): string
    {
        return Crypt::encryptString(self::PRAEFIX.($geladen ?? CarbonImmutable::now())->getTimestamp());
    }

    /**
     * Was mit dem Merkmal nicht stimmt -- oder null.
     *
     * Die Meldungen sagen nicht, was erkannt wurde: wer das Formular
     * automatisiert, soll aus der Antwort nichts lernen.
     */
    public function pruefe(string $merkmal, ?CarbonImmutable $jetzt = null): ?string
    {
        $jetzt ??= CarbonImmutable::now();

        try {
            $inhalt = Crypt::decryptString($merkmal);
        } catch (DecryptException) {
            return 'Bitte laden Sie die Seite neu und senden Sie das Formular noch einmal.';
        }

        if (! str_starts_with($inhalt, self::PRAEFIX) || ! ctype_digit(substr($inhalt, strlen(self::PRAEFIX)))) {
            return 'Bitte laden Sie die Seite neu und senden Sie das Formular noch einmal.';
        }

        $geladen = CarbonImmutable::createFromTimestamp((int) substr($inhalt, strlen(self::PRAEFIX)));
        $sekunden = (int) $geladen->diffInSeconds($jetzt, absolute: false);

        if ($sekunden < 0 || $sekunden > (int) config('mrs.oeffentlich.demoanfragen.merkmal_stunden') * 3600) {
            return 'Die Seite war zu lange geöffnet. Bitte laden Sie sie neu und senden Sie das Formular noch einmal.';
        }

        if ($sekunden < (int) config('mrs.oeffentlich.demoanfragen.mindestzeit_sekunden')) {
            return 'Diese Anfrage konnte nicht verarbeitet werden. Bitte versuchen Sie es in einem Moment noch einmal.';
        }

        return null;
    }
}
