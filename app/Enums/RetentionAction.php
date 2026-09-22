<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was mit abgelaufenen Daten geschieht.
 *
 * Der Unterschied ist nicht kosmetisch: geloescht ist weg, anonymisiert
 * bleibt zaehlbar. Wo eine Kennzahl am Datensatz haengt, waere Loeschen eine
 * rueckwirkende Aenderung der Auswertung.
 */
enum RetentionAction: string
{
    case Delete = 'delete';

    case Anonymize = 'anonymize';

    public function label(): string
    {
        return match ($this) {
            self::Delete => 'Löschen',
            self::Anonymize => 'Anonymisieren',
        };
    }
}
