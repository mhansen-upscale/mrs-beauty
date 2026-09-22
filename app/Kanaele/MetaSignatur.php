<?php

declare(strict_types=1);

namespace App\Kanaele;

use SensitiveParameter;

/**
 * Die Signatur einer Meta-Zustellung.
 *
 * Schritt 1 des Ablaufs aus docs/integrationen/meta.md: `X-Hub-Signature-256`
 * pruefen, bei Fehlschlag verwerfen. Vor allem anderen -- eine Zustellung,
 * die nicht von Meta stammt, wird nicht einmal gespeichert.
 *
 * **Zeitkonstant verglichen.** Ein Vergleich mit === verraet ueber die
 * Laufzeit, wie viele Zeichen stimmen. Bei einem HMAC mit dem App-Secret ist
 * das kein theoretisches Problem: wer den Endpunkt kennt, kann beliebig oft
 * fragen.
 */
final class MetaSignatur
{
    private const PRAEFIX = 'sha256=';

    public static function stimmt(
        string $rumpf,
        ?string $kopf,
        #[SensitiveParameter] ?string $geheimnis,
    ): bool {
        if (! is_string($kopf) || $kopf === '' || ! is_string($geheimnis) || $geheimnis === '') {
            return false;
        }

        if (! str_starts_with($kopf, self::PRAEFIX)) {
            return false;
        }

        return hash_equals(self::bilde($rumpf, $geheimnis), $kopf);
    }

    public static function bilde(string $rumpf, #[SensitiveParameter] string $geheimnis): string
    {
        return self::PRAEFIX.hash_hmac('sha256', $rumpf, $geheimnis);
    }
}
