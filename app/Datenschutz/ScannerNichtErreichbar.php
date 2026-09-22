<?php

declare(strict_types=1);

namespace App\Datenschutz;

use RuntimeException;

/** Der Pruefdienst hat nicht geantwortet. */
final class ScannerNichtErreichbar extends RuntimeException
{
    public function __construct(public readonly string $kurzgrund)
    {
        parent::__construct('Die Virenpruefung ist nicht erreichbar: '.$kurzgrund);
    }
}
