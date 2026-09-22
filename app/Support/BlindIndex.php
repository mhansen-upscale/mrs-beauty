<?php

declare(strict_types=1);

namespace App\Support;

use SensitiveParameter;

/**
 * Blinde Indizes (Entscheidung A6).
 *
 * Ein verschluesseltes Feld ist nicht durchsuchbar, weil jeder Schreibvorgang
 * einen eigenen Nonce bekommt. Der blinde Index macht den **exakten**
 * Vergleich wieder moeglich: ein HMAC ueber den normalisierten Wert, mit dem
 * Indexschluessel der Organisation.
 *
 * Eigener Schluessel je Organisation, damit dieselbe E-Mail-Adresse in zwei
 * Praxen nicht denselben Index ergibt -- sonst liesse sich ueber einen
 * Datenbankauszug feststellen, wer bei mehreren Praxen Kunde ist.
 *
 * **Nur Gleichheit.** Praefix-, Teil- und Volltextsuche sind damit
 * ausgeschlossen. Das ist Entscheidung P8, kein Versaeumnis.
 */
final class BlindIndex
{
    public static function hash(
        string $wert,
        #[SensitiveParameter] string $schluessel,
    ): string {
        return hash_hmac('sha256', self::normalize($wert), $schluessel, true);
    }

    /**
     * Ohne Normalisierung findet "Max@Praxis.de " den Datensatz nicht, der als
     * "max@praxis.de" angelegt wurde.
     *
     * Telefonnummern brauchen eine eigene Normalisierung (E.164). Die kommt
     * mit WP-16, wo sie hingehoert.
     */
    public static function normalize(string $wert): string
    {
        return mb_strtolower(trim($wert));
    }
}
