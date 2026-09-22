<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Ein Modell, das personenbezogene Felder fuehrt.
 *
 * Wer verschluesselte Felder hat, hat definitionsgemaess personenbezogene --
 * durchgesetzt durch tests/Feature/Audit/MaskierungsdeckungTest.php.
 */
interface HasPersonalData
{
    /**
     * Felder, die bei maskierter Impersonation ersetzt werden.
     *
     * @return list<string>
     */
    public function personalFields(): array;
}
