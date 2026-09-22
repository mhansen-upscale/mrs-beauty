<?php

declare(strict_types=1);

namespace App\Tenancy;

use SensitiveParameter;

/**
 * Die entpackten Schluessel einer Organisation. Leben nur im Arbeitsspeicher
 * und nur fuer die Dauer einer Anfrage.
 */
final class OrganizationKeys
{
    public function __construct(
        #[SensitiveParameter]
        public readonly string $dataEncryptionKey,
        #[SensitiveParameter]
        public readonly string $blindIndexKey,
    ) {}

    /**
     * Haelt die Schluessel aus Backtraces und var_dump heraus.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['dataEncryptionKey' => '***', 'blindIndexKey' => '***'];
    }
}
