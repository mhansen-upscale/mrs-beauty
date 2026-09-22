<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Es wurde versucht, die Organisation eines bestehenden Datensatzes zu
 * aendern. Ein Datensatz wechselt nicht den Mandanten -- er wird beim neuen
 * angelegt und beim alten geloescht, mit allem, was daran haengt.
 */
final class TenantMismatch extends RuntimeException
{
    public static function beimUmhaengen(string $modell): self
    {
        return new self(
            "organization_id von {$modell} kann nicht geaendert werden. Ein "
            .'Datensatz wechselt nicht den Mandanten.'
        );
    }
}
