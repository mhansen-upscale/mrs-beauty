<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Umrechnung zwischen der kanonischen UUID-Form und den 16 Bytes, die in der
 * Datenbank stehen (Entscheidung A4).
 *
 * Warum das ueberhaupt eine eigene Klasse ist: Eloquent wendet Casts auf
 * Attribute an, nicht auf Query-Bindings. Wuerde `id` zur Zeichenkette
 * gecastet, verglichen alle Beziehungen 36 Zeichen mit 16 Bytes und faenden
 * nichts -- ohne einen Fehler zu werfen. Deshalb bleibt `id` binaer, und die
 * Umrechnung passiert an den wenigen Stellen, an denen ein Mensch zusieht.
 *
 * Siehe docs/datenmodell.md, Abschnitt 0.5.
 */
final class Uuid
{
    private const BYTES = 16;

    /** Neue zeitsortierte UUID (Version 7), binaer. */
    public static function generate(): string
    {
        return self::toBinary((string) Str::uuid7());
    }

    /** Kanonische Form -> 16 Bytes. */
    public static function toBinary(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException("Keine gueltige UUID: {$uuid}");
        }

        $binary = hex2bin($hex);

        if ($binary === false) {
            throw new InvalidArgumentException("Keine gueltige UUID: {$uuid}");
        }

        return $binary;
    }

    /** 16 Bytes -> kanonische Form. */
    public static function toString(string $binary): string
    {
        if (strlen($binary) !== self::BYTES) {
            throw new InvalidArgumentException(
                'Erwartet werden 16 Bytes, erhalten: '.strlen($binary)
            );
        }

        $hex = bin2hex($binary);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }

    /**
     * Nimmt beide Formen entgegen und liefert Bytes.
     *
     * Gedacht fuer Aufrufstellen, an denen eine ID aus einer URL, aus einem
     * Job-Payload oder aus dem Modell selbst kommen kann.
     */
    public static function normalize(string $value): string
    {
        return strlen($value) === self::BYTES ? $value : self::toBinary($value);
    }

    public static function isCanonical(string $value): bool
    {
        return Str::isUuid($value);
    }
}
