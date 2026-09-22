<?php

declare(strict_types=1);

namespace App\Datenschutz;

use RuntimeException;

/**
 * Dieser Anhang wird nicht angenommen oder nicht herausgegeben.
 */
final class Anhangabgelehnt extends RuntimeException
{
    public static function ohneAblaufdatum(): self
    {
        return new self('Ein Chat-Anhang braucht ein Ablaufdatum (Entscheidung C6).');
    }

    public static function nichtFreigegeben(): self
    {
        return new self('Dieser Anhang ist noch nicht geprüft oder wurde beanstandet.');
    }

    /**
     * Der Datensatz steht, die Datei fehlt.
     *
     * **Ein stiller Ausfall waere hier der teuerste.** Vorher gab der
     * Speicher eine leere Zeichenkette zurueck; die Anzeige streamte null
     * Bytes und niemand erfuhr warum. Mit einem Bucket statt einer lokalen
     * Platte ist das kein Sonderfall mehr: falsche Region, fehlende
     * Berechtigung, geloeschtes Objekt.
     */
    public static function nichtImSpeicher(string $pfad): self
    {
        return new self('Die Datei zu diesem Anhang liegt nicht mehr im Speicher ('.$pfad.').');
    }
}
