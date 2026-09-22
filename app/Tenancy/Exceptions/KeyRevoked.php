<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Der Schluessel der Organisation ist widerrufen. Die Daten sind damit
 * dauerhaft unlesbar -- das ist der Zweck der Krypto-Loeschung, kein Defekt.
 */
final class KeyRevoked extends RuntimeException
{
    public static function fuer(string $uuid): self
    {
        return new self(
            "Der Schluessel der Organisation {$uuid} ist widerrufen. Die Daten "
            .'dieser Organisation sind dauerhaft unlesbar (Krypto-Loeschung).'
        );
    }
}
