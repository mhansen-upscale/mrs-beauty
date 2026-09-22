<?php

declare(strict_types=1);

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Telefonnummern in eine vergleichbare Form bringen (E.164).
 *
 * **Das ist keine Kosmetik an der Eingabe.** Ein blinder Index vergleicht
 * Hashes; "+49 170 1234567" und "01701234567" ergeben ohne Normalisierung
 * zwei verschiedene und damit zwei Personen, die niemand zusammenfuehrt. Die
 * Suche traefe still nie -- kein Fehler, keine Meldung, kein Ergebnis.
 *
 * Die Umsetzung kommt aus Googles Referenzbibliothek. Selbst zu bauen waere
 * der falsche Sparzwang: Durchwahlen, Servicenummern und zwei Schreibweisen
 * fuer das Auslandspraefix bemerkt man beim Selberbauen genau so lange nicht,
 * bis eine Praxis anruft.
 */
final class Telefonnummer
{
    /**
     * Die kanonische Form, oder null.
     *
     * Null heisst: nicht als Nummer lesbar. Der Aufrufer speichert dann, was
     * eingegeben wurde, und verzichtet auf den Index -- eine unleserliche
     * Nummer ist ein Kontaktweg, den jemand abtippen kann. Sie zu verwerfen
     * waere schlimmer, als sie nicht zu finden.
     */
    public static function e164(string $eingabe, ?string $region = null): ?string
    {
        $nummer = self::lies($eingabe, $region);

        return $nummer === null
            ? null
            : PhoneNumberUtil::getInstance()->format($nummer, PhoneNumberFormat::E164);
    }

    /** Lesbar fuer Menschen -- international, damit die Vorwahl sichtbar bleibt. */
    public static function anzeige(string $eingabe, ?string $region = null): string
    {
        $nummer = self::lies($eingabe, $region);

        return $nummer === null
            ? trim($eingabe)
            : PhoneNumberUtil::getInstance()->format($nummer, PhoneNumberFormat::INTERNATIONAL);
    }

    public static function istGueltig(string $eingabe, ?string $region = null): bool
    {
        return self::lies($eingabe, $region) !== null;
    }

    private static function lies(string $eingabe, ?string $region): ?PhoneNumber
    {
        $eingabe = trim($eingabe);

        if ($eingabe === '') {
            return null;
        }

        $region ??= (string) config('mrs.contacts.default_region', 'DE');

        try {
            $nummer = PhoneNumberUtil::getInstance()->parse($eingabe, $region);
        } catch (NumberParseException) {
            return null;
        }

        return PhoneNumberUtil::getInstance()->isValidNumber($nummer) ? $nummer : null;
    }
}
