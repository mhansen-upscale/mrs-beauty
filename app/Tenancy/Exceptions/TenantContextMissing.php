<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Es wurde auf Mandantendaten zugegriffen, ohne dass ein Mandant gesetzt war.
 *
 * Das ist bewusst eine Ausnahme und kein leeres Ergebnis. Ein leeres Ergebnis
 * sieht aus wie "keine Daten vorhanden" und wird tagelang als Fachfehler
 * gesucht. Siehe docs/datenmodell.md, Abschnitt 0.1.
 */
final class TenantContextMissing extends RuntimeException
{
    public static function beimZugriff(string $modell): self
    {
        return new self(
            "Zugriff auf {$modell} ohne Mandantenkontext. Entweder fehlt die "
            .'Aufloesung des Mandanten (Middleware, Job, Konsolenbefehl), oder '
            .'der Zugriff gehoert ausdruecklich in Tenancy::acrossTenants().'
        );
    }
}
