<?php

declare(strict_types=1);

namespace App\Abrechnung\Stripe;

/**
 * Prueft die Signatur einer Stripe-Zustellung.
 *
 * **Ein Webhook ohne Signaturpruefung laesst jeden das Abo verlaengern.**
 * Dieselbe Ueberlegung wie bei MetaSignatur (WP-19), nur mit anderem Format:
 * `t=<Zeitstempel>,v1=<HMAC>` ueber `<Zeitstempel>.<Rumpf>`.
 *
 * Verglichen wird **zeitkonstant**, und der Zeitstempel wird geprueft: eine
 * aufgezeichnete Zustellung soll sich nicht Wochen spaeter erneut abspielen
 * lassen.
 */
final class Stripesignatur
{
    /** Wie alt eine Zustellung hoechstens sein darf. */
    private const TOLERANZ_SEKUNDEN = 300;

    public static function stimmt(string $rumpf, ?string $kopf, string $geheimnis, ?int $jetzt = null): bool
    {
        if ($geheimnis === '' || ! is_string($kopf) || $kopf === '') {
            return false;
        }

        $jetzt ??= time();
        $zeitstempel = null;
        $signaturen = [];

        foreach (explode(',', $kopf) as $teil) {
            [$schluessel, $wert] = array_pad(explode('=', trim($teil), 2), 2, '');

            if ($schluessel === 't' && is_numeric($wert)) {
                $zeitstempel = (int) $wert;
            }

            if ($schluessel === 'v1' && $wert !== '') {
                $signaturen[] = $wert;
            }
        }

        if ($zeitstempel === null || $signaturen === []) {
            return false;
        }

        if (abs($jetzt - $zeitstempel) > self::TOLERANZ_SEKUNDEN) {
            return false;
        }

        $erwartet = hash_hmac('sha256', $zeitstempel.'.'.$rumpf, $geheimnis);

        foreach ($signaturen as $signatur) {
            if (hash_equals($erwartet, $signatur)) {
                return true;
            }
        }

        return false;
    }
}
