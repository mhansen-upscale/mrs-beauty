<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * Feldverschluesselung mit AES-256-GCM (Entscheidung A6).
 *
 * Aufbau der Nutzlast:
 *
 *   [1 Byte Version][12 Byte Nonce][16 Byte Auth-Tag][Chiffrat]
 *
 * Die Version steht vorn, damit ein spaeterer Wechsel des Verfahrens alte
 * Werte noch lesen kann. Der Nonce wird je Schreibvorgang neu gezogen --
 * derselbe Klartext ergibt deshalb zweimal verschiedene Chiffrate. Genau
 * deswegen ist ein verschluesseltes Feld nicht durchsuchbar, und genau
 * deswegen gibt es blinde Indizes.
 */
final class FieldCipher
{
    private const VERSION = "\x01";

    private const VERFAHREN = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    public static function encrypt(
        string $klartext,
        #[SensitiveParameter] string $schluessel,
    ): string {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $chiffrat = openssl_encrypt(
            $klartext,
            self::VERFAHREN,
            $schluessel,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($chiffrat === false) {
            throw new RuntimeException('Verschluesselung fehlgeschlagen.');
        }

        return self::VERSION.$nonce.$tag.$chiffrat;
    }

    public static function decrypt(
        string $nutzlast,
        #[SensitiveParameter] string $schluessel,
    ): string {
        $kopflaenge = 1 + self::NONCE_BYTES + self::TAG_BYTES;

        if (strlen($nutzlast) < $kopflaenge) {
            throw new RuntimeException('Nutzlast ist zu kurz fuer ein Chiffrat.');
        }

        $version = $nutzlast[0];

        if ($version !== self::VERSION) {
            throw new RuntimeException(
                'Unbekannte Chiffratversion: '.bin2hex($version)
            );
        }

        $nonce = substr($nutzlast, 1, self::NONCE_BYTES);
        $tag = substr($nutzlast, 1 + self::NONCE_BYTES, self::TAG_BYTES);
        $chiffrat = substr($nutzlast, $kopflaenge);

        $klartext = openssl_decrypt(
            $chiffrat,
            self::VERFAHREN,
            $schluessel,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($klartext === false) {
            throw new RuntimeException(
                'Entschluesselung fehlgeschlagen. Entweder gehoert der Wert zu '
                .'einer anderen Organisation, oder er wurde veraendert.'
            );
        }

        return $klartext;
    }
}
